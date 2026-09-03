<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformResource;
use App\Models\Ticket;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class BoardingController extends Controller
{
    public function manifest(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeTrip($request, $trip);

        $passengers = Ticket::query()
            ->whereHas('passenger', fn ($query) => $query->where('trip_id', $trip->id))
            ->with(['passenger.seat', 'passenger.booking:id,reference,status,contact_phone'])
            ->get()
            ->map(fn (Ticket $ticket): array => $this->ticketPayload($ticket));

        return response()->json([
            'trip_id' => $trip->id,
            'counts' => [
                'passengers' => $passengers->count(),
                'checked_in' => $passengers->whereNotNull('checked_in_at')->count(),
                'boarded' => $passengers->whereNotNull('boarded_at')->count(),
                'absent' => $passengers->whereNotNull('absent_at')->count(),
            ],
            'passengers' => $passengers,
        ]);
    }

    public function scan(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('boarding.manage'), 403);
        $validated = $request->validate($this->scanRules());

        return response()->json($this->processScan($request, $validated));
    }

    /**
     * Bulk-apply scans a device recorded while offline (e.g. a conductor's app that lost signal
     * mid-route). Each scan is applied independently and reports its own outcome, so one bad or
     * already-applied entry in the batch does not block the rest from syncing.
     */
    public function syncScans(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('boarding.manage'), 403);
        $validated = $request->validate(['scans' => ['required', 'array', 'min:1', 'max:200'], 'scans.*' => ['array']]);
        $results = collect($validated['scans'])->map(function (array $scan) use ($request): array {
            try {
                $clean = validator($scan, $this->scanRules())->validate();

                return ['code' => $scan['code'] ?? null, 'action' => $scan['action'] ?? null, 'status' => 'applied', 'ticket' => $this->processScan($request, $clean)];
            } catch (ValidationException $exception) {
                return ['code' => $scan['code'] ?? null, 'action' => $scan['action'] ?? null, 'status' => 'failed', 'error' => collect($exception->errors())->flatten()->implode(' ')];
            } catch (Throwable $exception) {
                return ['code' => $scan['code'] ?? null, 'action' => $scan['action'] ?? null, 'status' => 'failed', 'error' => $exception->getMessage() ?: 'This scan could not be applied.'];
            }
        });

        return response()->json(['applied' => $results->where('status', 'applied')->count(), 'failed' => $results->where('status', 'failed')->count(), 'results' => $results->values()]);
    }

    /** @return array<string, mixed> */
    private function scanRules(): array
    {
        return [
            'code' => ['required', 'string', 'max:191'],
            'action' => ['required', 'in:check_in,board,absent'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'offline_recorded_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function processScan(Request $request, array $validated): array
    {
        return DB::transaction(function () use ($request, $validated): array {
            $ticket = Ticket::query()
                ->where(fn ($query) => $query->where('qr_token', $validated['code'])->orWhere('ticket_number', $validated['code']))
                ->with(['passenger.seat', 'passenger.booking.trip'])
                ->lockForUpdate()
                ->firstOrFail();
            $this->authorizeTrip($request, $ticket->passenger->booking->trip);
            abort_unless($ticket->status === 'active' && $ticket->passenger->booking->status === 'confirmed', 409, 'Ticket is not active and paid.');

            $timestampColumn = match ($validated['action']) {
                'check_in' => 'checked_in_at',
                'board' => 'boarded_at',
                'absent' => 'absent_at',
            };
            $staffColumn = match ($validated['action']) {
                'check_in' => 'checked_in_by',
                'board' => 'boarded_by',
                'absent' => 'marked_absent_by',
            };
            abort_if($ticket->{$timestampColumn} !== null, 409, 'This ticket action was already recorded.');
            abort_if($validated['action'] === 'board' && $ticket->checked_in_at === null, 409, 'Passenger must be checked in before boarding.');
            abort_if($validated['action'] === 'absent' && $ticket->boarded_at !== null, 409, 'A boarded passenger cannot be marked absent.');

            $recordedAt = isset($validated['offline_recorded_at']) ? now()->parse($validated['offline_recorded_at']) : now();
            $ticket->update([$timestampColumn => $recordedAt, $staffColumn => $request->user()->id]);
            $scan = (new PlatformResource)->useModule('boarding_scans');
            $scan->fill(['company_id' => $ticket->passenger->booking->company_id, 'user_id' => $request->user()->id, 'code' => $ticket->ticket_number.':'.$validated['action'], 'name' => $validated['action'], 'status' => 'recorded', 'data' => ['ticket_id' => $ticket->id, 'trip_id' => $ticket->passenger->trip_id, 'device_id' => $validated['device_id'] ?? null, 'recorded_at' => $recordedAt->toIso8601String()]])->save();

            return $this->ticketPayload($ticket->refresh()->load(['passenger.seat', 'passenger.booking']));
        });
    }

    private function authorizeTrip(Request $request, Trip $trip): void
    {
        abort_unless($request->user()->can('boarding.manage') && $request->user()->company_id === $trip->company_id, 404);
    }

    /** @return array<string, mixed> */
    private function ticketPayload(Ticket $ticket): array
    {
        return [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'booking_reference' => $ticket->passenger->booking->reference,
            'passenger_name' => $ticket->passenger->full_name,
            'seat_number' => $ticket->passenger->seat->number,
            'payment_status' => $ticket->passenger->booking->status,
            'checked_in_at' => $ticket->checked_in_at?->toIso8601String(),
            'boarded_at' => $ticket->boarded_at?->toIso8601String(),
            'absent_at' => $ticket->absent_at?->toIso8601String(),
        ];
    }
}
