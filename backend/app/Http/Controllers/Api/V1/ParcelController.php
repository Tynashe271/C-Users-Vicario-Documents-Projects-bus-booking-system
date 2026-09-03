<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Parcel;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Services\FinanceService;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ParcelController extends Controller
{
    public function quote(Request $request): JsonResponse
    {
        $validated = $request->validate($this->measurementRules());
        $route = TransportRoute::findOrFail($validated['route_id']);
        $chargeableWeight = max((float) $validated['weight_kg'], ((float) $validated['length_cm'] * (float) $validated['width_cm'] * (float) $validated['height_cm']) / 5000);
        $amount = round(2 + (($route->distance_km ?? 100) * 0.03) + ($chargeableWeight * 0.5), 2);

        return response()->json(['amount' => $amount, 'currency' => $route->company?->currency ?? 'USD', 'chargeable_weight_kg' => round($chargeableWeight, 2), 'breakdown' => ['base' => 2, 'distance' => round(($route->distance_km ?? 100) * 0.03, 2), 'weight' => round($chargeableWeight * 0.5, 2)]]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $validated = $request->validate([...$this->measurementRules(), 'sender_name' => ['required', 'string', 'max:150'], 'sender_phone' => ['required', 'string', 'max:30'], 'receiver_name' => ['required', 'string', 'max:150'], 'receiver_phone' => ['required', 'string', 'max:30'], 'description' => ['required', 'string', 'max:1000'], 'prohibited_items_declared' => ['required', 'accepted']]);
        $route = TransportRoute::whereKey($validated['route_id'])->where('company_id', $companyId)->firstOrFail();
        $quote = $this->quote(new Request($this->measurements($validated)))->getData(true);
        $collectionCode = (string) random_int(100000, 999999);
        $parcel = Parcel::create([...$validated, 'public_id' => Str::uuid(), 'tracking_number' => 'PX'.strtoupper(Str::random(10)), 'company_id' => $companyId, 'user_id' => $request->user()->id, 'name' => 'Parcel to '.$route->destination?->name, 'status' => 'booked', 'amount' => $quote['amount'], 'currency' => $quote['currency'], 'qr_token' => hash('sha256', Str::uuid()), 'collection_code_hash' => hash('sha256', $collectionCode)]);
        $this->event($parcel, 'booked', $request, 'Parcel booking created.');

        return response()->json(['parcel' => $parcel, 'collection_code' => $collectionCode], 201);
    }

    public function assign(Request $request, Parcel $parcel): JsonResponse
    {
        $this->authorizeParcel($request, $parcel);
        $validated = $request->validate(['trip_id' => ['required', 'integer', 'exists:trips,id']]);
        $trip = Trip::whereKey($validated['trip_id'])->where('company_id', $parcel->company_id)->where('route_id', $parcel->route_id)->firstOrFail();
        abort_unless(in_array($parcel->status, ['booked', 'assigned'], true), 409, 'Parcel can no longer be assigned.');
        $parcel->update(['trip_id' => $trip->id, 'status' => 'assigned']);
        $this->event($parcel, 'assigned', $request, 'Assigned to trip '.$trip->id, $trip->id);

        return response()->json($parcel->refresh());
    }

    public function markPaid(Request $request, Parcel $parcel, FinanceService $finance): JsonResponse
    {
        $this->authorizeParcel($request, $parcel);
        $validated = $request->validate(['payment_reference' => ['required', 'string', 'max:191'], 'amount' => ['required', 'numeric', 'min:0']]);
        abort_unless((float) $validated['amount'] === (float) $parcel->amount, 422, 'Payment amount does not match the parcel charge.');
        abort_unless($parcel->payment_status === 'pending', 409, 'Parcel payment was already recorded.');
        DB::transaction(function () use ($parcel, $validated, $request, $finance): void {
            $parcel->update(['payment_status' => 'paid', 'data' => [...($parcel->data ?? []), 'payment_reference' => $validated['payment_reference'], 'paid_at' => now()->toIso8601String()]]);
            $finance->allocateConfirmedParcel($parcel->refresh());
            $this->event($parcel, 'payment_confirmed', $request, 'Parcel payment confirmed.');
        });

        return response()->json($parcel->refresh());
    }

    public function transition(Request $request, Parcel $parcel): JsonResponse
    {
        $this->authorizeParcel($request, $parcel);
        $validated = $request->validate(['event_type' => ['required', Rule::in(['checked_in', 'loaded', 'in_transit', 'arrived', 'lost_claim', 'damaged_claim'])], 'terminal_id' => ['nullable', 'integer', 'exists:terminals,id'], 'notes' => ['nullable', 'string', 'max:2000'], 'latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180']]);
        $allowed = ['assigned' => ['checked_in'], 'checked_in' => ['loaded'], 'loaded' => ['in_transit'], 'in_transit' => ['arrived'], 'arrived' => ['lost_claim', 'damaged_claim'], 'booked' => ['lost_claim', 'damaged_claim']];
        abort_unless(in_array($validated['event_type'], $allowed[$parcel->status] ?? [], true), 409, 'Invalid parcel status transition.');
        abort_if($validated['event_type'] === 'loaded' && $parcel->payment_status !== 'paid', 409, 'Parcel must be paid before loading.');
        DB::transaction(function () use ($parcel, $validated, $request): void {
            $parcel->update(['status' => $validated['event_type']]);
            $this->event($parcel, $validated['event_type'], $request, $validated['notes'] ?? null, $parcel->trip_id, $validated);
        });

        return response()->json($parcel->refresh()->load('events'));
    }

    public function collect(Request $request, Parcel $parcel): JsonResponse
    {
        $this->authorizeParcel($request, $parcel);
        $validated = $request->validate(['collection_code' => ['required', 'digits:6'], 'proof_of_collection_path' => ['required', 'string', 'max:2048']]);
        abort_unless($parcel->status === 'arrived', 409, 'Parcel is not ready for collection.');
        abort_unless(hash_equals((string) $parcel->collection_code_hash, hash('sha256', $validated['collection_code'])), 422, 'Collection code is invalid.');
        DB::transaction(function () use ($parcel, $validated, $request): void {
            $parcel->update(['status' => 'collected', 'proof_of_collection_path' => $validated['proof_of_collection_path'], 'collected_at' => now()]);
            $this->event($parcel, 'collected', $request, 'Receiver identity and collection code verified.');
        });

        return response()->json($parcel->refresh());
    }

    public function track(string $trackingNumber): JsonResponse
    {
        $parcel = Parcel::where('tracking_number', strtoupper($trackingNumber))->with(['route.origin', 'route.destination', 'events' => fn ($query) => $query->orderBy('recorded_at')])->firstOrFail();

        return response()->json(['tracking_number' => $parcel->tracking_number, 'status' => $parcel->status, 'route' => ['origin' => $parcel->route->origin->name, 'destination' => $parcel->route->destination->name], 'events' => $parcel->events->map(fn ($event) => ['type' => $event->event_type, 'recorded_at' => $event->recorded_at?->toIso8601String(), 'terminal_id' => $event->terminal_id])]);
    }

    public function label(Request $request, Parcel $parcel): Response
    {
        $this->authorizeParcel($request, $parcel);
        $svg = (new SvgWriter)->write(new QrCode(data: $parcel->qr_token, size: 260, margin: 10))->getString();

        return response($svg)->header('Content-Type', 'image/svg+xml')->header('Content-Disposition', 'inline; filename="'.$parcel->tracking_number.'.svg"');
    }

    public function report(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $base = Parcel::query()->where('company_id', $companyId);

        $commissions = Commission::where('company_id', $companyId)->whereNotNull('parcel_id');

        return response()->json(['total_parcels' => (clone $base)->count(), 'collected' => (clone $base)->where('status', 'collected')->count(), 'active' => (clone $base)->whereNotIn('status', ['collected', 'lost_claim', 'damaged_claim'])->count(), 'claims' => (clone $base)->whereIn('status', ['lost_claim', 'damaged_claim'])->count(), 'revenue' => (float) (clone $base)->where('payment_status', 'paid')->sum('amount'), 'platform_commission' => round((float) (clone $commissions)->sum('platform_amount'), 2), 'operator_earnings' => round((float) (clone $commissions)->sum('operator_amount'), 2)]);
    }

    /** @return array<string, mixed> */
    private function measurementRules(): array
    {
        return ['route_id' => ['required', 'integer', 'exists:routes,id'], 'weight_kg' => ['required', 'numeric', 'gt:0', 'max:1000'], 'length_cm' => ['required', 'numeric', 'gt:0', 'max:500'], 'width_cm' => ['required', 'numeric', 'gt:0', 'max:500'], 'height_cm' => ['required', 'numeric', 'gt:0', 'max:500']];
    }

    /** @return array<string, mixed> */
    private function measurements(array $data): array
    {
        return array_intersect_key($data, array_flip(['route_id', 'weight_kg', 'length_cm', 'width_cm', 'height_cm']));
    }

    private function companyId(Request $request): int
    {
        abort_unless($request->user()->company_id && $request->user()->can('parcels.manage'), 403);

        return $request->user()->company_id;
    }

    private function authorizeParcel(Request $request, Parcel $parcel): void
    {
        abort_unless($parcel->company_id === $this->companyId($request), 404);
    }

    /** @param array<string, mixed> $details */
    private function event(Parcel $parcel, string $type, Request $request, ?string $notes = null, ?int $tripId = null, array $details = []): void
    {
        $parcel->events()->create(['company_id' => $parcel->company_id, 'user_id' => $request->user()->id, 'trip_id' => $tripId, 'terminal_id' => $details['terminal_id'] ?? null, 'code' => Str::uuid(), 'name' => str($type)->headline(), 'event_type' => $type, 'status' => 'recorded', 'notes' => $notes, 'latitude' => $details['latitude'] ?? null, 'longitude' => $details['longitude'] ?? null, 'recorded_at' => now()]);
    }
}
