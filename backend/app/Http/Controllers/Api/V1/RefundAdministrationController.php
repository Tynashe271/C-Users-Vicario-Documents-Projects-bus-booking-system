<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PlatformResource;
use App\Services\BookingCancellationService;
use App\Services\PassengerJourneyNotificationService;
use App\Services\WalletService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RefundAdministrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['status' => ['nullable', 'string', 'max:50']]);
        $query = $this->scopedQuery($request);
        $query->when($validated['status'] ?? null, fn (Builder $builder, string $status) => $builder->where('status', $status));

        return response()->json($query->latest()->paginate(25));
    }

    public function show(Request $request, int $refund): JsonResponse
    {
        $record = $this->find($request, $refund);
        $booking = Booking::with(['passengers', 'trip.company'])->find(data_get($record->data, 'booking_id'));

        return response()->json(['refund' => $record, 'booking' => $booking, 'policy_check' => $booking ? app(BookingCancellationService::class)->quote($booking, $booking->passengers) : null]);
    }

    public function approve(Request $request, int $refund, PaymentGateway $gateway, WalletService $wallets, BookingCancellationService $cancellations, PassengerJourneyNotificationService $notifications): JsonResponse
    {
        $record = $this->find($request, $refund);
        abort_unless(in_array($record->status, ['pending', 'requested', 'under_review'], true), 409, 'This refund was already decided.');
        $booking = Booking::with(['user', 'payments', 'passengers', 'trip.company'])->find(data_get($record->data, 'booking_id'));
        abort_unless($booking, 422, 'The originating booking could not be found.');
        $amount = (float) $record->amount;
        $eligible = (float) $cancellations->quote($booking, $booking->passengers)['eligible_amount'];
        abort_if($amount > $eligible + 0.01, 422, 'The refund amount exceeds the policy-eligible amount for this booking.');
        $method = data_get($record->data, 'method', 'original');

        DB::transaction(function () use ($record, $booking, $amount, $method, $gateway, $wallets, $request): void {
            if ($method === 'wallet') {
                abort_unless($booking->user, 422, 'A wallet refund requires the booking to belong to a registered passenger account.');
                $wallets->credit($booking->user, $amount, 'refund', 'refund:'.$record->id, 'Refund for booking '.$booking->reference);
            } else {
                $payment = $booking->payments->where('status', 'paid')->sortByDesc('paid_at')->first();
                abort_unless($payment, 422, 'No paid payment was found to refund to its original method.');
                $result = $gateway->refund($payment->provider, (string) $payment->provider_reference, $amount, 'refund:'.$record->id);
                // The gateway may echo back the original payment's provider_reference (e.g. when no
                // refund_url is configured), which would collide with that payment's own row under
                // the (provider, provider_reference) unique constraint — so this refund row's
                // reference is always distinct from it.
                Payment::create(['booking_id' => $booking->id, 'provider' => $payment->provider, 'provider_reference' => $result['provider_reference'].':refund:'.$record->id, 'idempotency_key' => 'refund:'.$record->id, 'amount' => -$amount, 'currency' => $payment->currency, 'status' => $result['status'] === 'paid' ? 'refunded' : 'refund_pending', 'paid_at' => $result['status'] === 'paid' ? now() : null]);
            }
            $record->update(['status' => 'approved', 'data' => [...($record->data ?? []), 'method' => $method, 'approved_by' => $request->user()->id, 'approved_at' => now()->toIso8601String()]]);
        });

        $notifications->refundStatus($booking, 'approved', $amount);

        return response()->json($record->refresh());
    }

    public function reject(Request $request, int $refund, PassengerJourneyNotificationService $notifications): JsonResponse
    {
        $record = $this->find($request, $refund);
        abort_unless(in_array($record->status, ['pending', 'requested', 'under_review'], true), 409, 'This refund was already decided.');
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $record->update(['status' => 'rejected', 'data' => [...($record->data ?? []), 'rejected_by' => $request->user()->id, 'rejected_at' => now()->toIso8601String(), 'rejection_reason' => $validated['reason']]]);
        $booking = Booking::find(data_get($record->data, 'booking_id'));
        if ($booking) {
            $notifications->refundStatus($booking, 'rejected', (float) $record->amount);
        }

        return response()->json($record->refresh());
    }

    public function report(Request $request): JsonResponse
    {
        $records = $this->scopedQuery($request)->get();
        $decided = $records->whereIn('status', ['approved', 'rejected']);
        $processingHours = $decided->map(function (PlatformResource $refund): ?float {
            $decidedAt = data_get($refund->data, 'approved_at') ?? data_get($refund->data, 'rejected_at');

            return $decidedAt ? $refund->created_at->diffInHours(now()->parse($decidedAt)) : null;
        })->filter(fn (?float $hours): bool => $hours !== null);

        return response()->json([
            'total_requests' => $records->count(), 'pending' => $records->whereIn('status', ['pending', 'requested', 'under_review'])->count(),
            'approved' => $records->where('status', 'approved')->count(), 'rejected' => $records->where('status', 'rejected')->count(),
            'total_refunded' => round((float) $records->where('status', 'approved')->sum('amount'), 2),
            'by_method' => $records->where('status', 'approved')->groupBy(fn (PlatformResource $refund) => data_get($refund->data, 'method', 'original'))->map->count(),
            'average_processing_hours' => $processingHours->isEmpty() ? null : round($processingHours->avg(), 1),
        ]);
    }

    private function scopedQuery(Request $request): Builder
    {
        abort_unless($request->user()->can('finance.manage'), 403);
        $query = (new PlatformResource)->useModule('refunds')->newQuery();
        if (! $this->isPlatformUser($request)) {
            abort_unless($request->user()->company_id, 403);
            $query->where('company_id', $request->user()->company_id);
        }

        return $query;
    }

    private function find(Request $request, int $id): PlatformResource
    {
        return $this->scopedQuery($request)->findOrFail($id);
    }

    private function isPlatformUser(Request $request): bool
    {
        return in_array($request->user()->role, config('platform.platform_roles'), true);
    }
}
