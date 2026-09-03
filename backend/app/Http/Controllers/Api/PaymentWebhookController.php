<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PlatformResource;
use App\Services\BookingService;
use App\Services\FinanceService;
use App\Services\PassengerJourneyNotificationService;
use App\Services\TicketDeliveryService;
use App\Services\TripOccupancyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentWebhookController extends Controller
{
    public function __invoke(Request $r, string $provider, BookingService $service, FinanceService $finance, TicketDeliveryService $delivery, PassengerJourneyNotificationService $notifications, TripOccupancyService $occupancy)
    {
        $secret = (string) (config("payments.providers.$provider.webhook_secret") ?: config('booking.webhook_secret'));
        abort_if($secret === '' || ! hash_equals(hash_hmac('sha256', $r->getContent(), $secret), (string) $r->header('X-Signature')), 401);
        $v = $r->validate(['event_id' => 'required|string|max:191', 'booking_reference' => 'required|string', 'provider_reference' => 'required|string', 'status' => 'required|in:paid,failed,pending', 'failure_is_permanent' => 'sometimes|boolean', 'amount' => 'required|numeric|min:0', 'currency' => 'required|string|size:3']);
        DB::transaction(function () use ($v, $provider, $r, $service, $finance, $delivery, $notifications, $occupancy) {
            $booking = Booking::where('reference', $v['booking_reference'])->lockForUpdate()->firstOrFail();
            $eventKey = $provider.':'.$v['event_id'];
            $events = (new PlatformResource)->useModule('webhook_events');
            if ($events->newQuery()->where('company_id', $booking->company_id)->where('code', $eventKey)->lockForUpdate()->exists()) {
                return;
            }
            $events->fill(['company_id' => $booking->company_id, 'code' => $eventKey, 'name' => 'Payment webhook', 'status' => 'processed', 'data' => ['provider' => $provider, 'payload' => $r->all()]])->save();
            $payment = Payment::where('booking_id', $booking->id)->where('provider', $provider)->where('provider_reference', $v['provider_reference'])->lockForUpdate()->first();
            if (! $payment) {
                $payment = Payment::create(['idempotency_key' => 'webhook:'.$eventKey, 'booking_id' => $booking->id, 'provider' => $provider, 'provider_reference' => $v['provider_reference'], 'amount' => $v['amount'], 'currency' => strtoupper($v['currency']), 'provider_payload' => $r->all()]);
            } else {
                $payment->update(['provider_payload' => $r->all()]);
            }
            if ($v['status'] === 'paid' && (float) $v['amount'] === (float) $payment->amount && strtoupper($v['currency']) === $booking->currency) {
                $payment->update(['status' => 'paid', 'paid_at' => now()]);
                $paid = (float) Payment::where('booking_id', $booking->id)->where('status', 'paid')->sum('amount');
                if ($paid >= (float) $booking->total) {
                    abort_if($booking->status === 'expired' || ($booking->payable_until && $booking->payable_until->isPast()), 409, 'This booking has expired.');
                    $booking->update(['status' => 'confirmed', 'payable_until' => null]);
                    $booking->passengers()->update(['status' => 'confirmed']);
                    $service->confirmPaidBooking($booking, $payment, $finance, $delivery, $notifications, $occupancy);
                }
            } else {
                $payment->update(['status' => $v['status'] === 'paid' ? 'amount_mismatch' : $v['status']]);
                if ($v['status'] === 'failed' && ($v['failure_is_permanent'] ?? false)) {
                    $service->releaseAfterPermanentPaymentFailure($booking);
                }
            }
        });

        return response()->json(['received' => true]);
    }
}
