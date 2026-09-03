<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\BookingService;
use App\Services\FinanceService;
use App\Services\PassengerJourneyNotificationService;
use App\Services\TicketDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class PaymentController extends Controller
{
    public function store(Request $request, Booking $booking, PaymentGateway $gateway, BookingService $bookingService, FinanceService $financeService, TicketDeliveryService $delivery, PassengerJourneyNotificationService $notifications): JsonResponse
    {
        $user = $request->user();
        $allowed = $request->routeIs('guest.payments.store')
            ? $booking->user_id === null
            : $user && ($booking->user_id === $user->id || ($booking->company_id === $user->company_id && $user->can('bookings.manage')));
        abort_unless($allowed, 404);
        abort_unless(in_array($booking->status, ['pending_payment', 'confirmed'], true) && (! $booking->payable_until || $booking->payable_until->isFuture()), 409, 'This booking is not payable.');
        $providers = array_keys(config('payments.providers'));
        $validated = $request->validate(['provider' => ['required', Rule::in($providers)], 'amount' => ['required', 'numeric', 'gt:0'], 'context' => ['sometimes', 'array']]);
        $idempotencyKey = $request->header('Idempotency-Key');
        abort_unless(is_string($idempotencyKey) && mb_strlen($idempotencyKey) >= 16 && mb_strlen($idempotencyKey) <= 191, 422, 'A valid Idempotency-Key header is required.');
        $payment = DB::transaction(function () use ($booking, $validated, $idempotencyKey): Payment {
            $existing = Payment::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $paid = (float) Payment::where('booking_id', $booking->id)->where('status', 'paid')->sum('amount');
            $pending = (float) Payment::where('booking_id', $booking->id)->where('status', 'pending')->sum('amount');
            $outstanding = round((float) $booking->total - $paid - $pending, 2);
            abort_if((float) $validated['amount'] > $outstanding, 422, 'Payment amount exceeds the outstanding balance.');

            return Payment::firstOrCreate(['idempotency_key' => $idempotencyKey], ['booking_id' => $booking->id, 'provider' => $validated['provider'], 'amount' => $validated['amount'], 'currency' => $booking->currency, 'status' => 'initiating']);
        });
        abort_unless($payment->booking_id === $booking->id, 409, 'This Idempotency-Key was already used for another booking.');
        if (! $payment->wasRecentlyCreated && $payment->status !== 'failed') {
            return response()->json($payment);
        }

        if ($validated['provider'] === 'passenger_wallet') {
            abort_unless($booking->user_id !== null, 422, 'Passenger wallet payment requires an account.');
            $financeService->payFromPassengerWallet($booking, $payment, $booking->user_id, $validated['context']['pin'] ?? null);
            $payment = $payment->refresh();
            $this->completeBooking($booking, $payment, $bookingService, $financeService, $delivery, $notifications);

            return response()->json($payment, 201);
        }

        try {
            $result = $gateway->initiate($validated['provider'], $booking, (float) $validated['amount'], $idempotencyKey, $validated['context'] ?? []);
            $payment->update(['provider_reference' => $result['provider_reference'], 'status' => $result['status'], 'provider_payload' => $result, 'paid_at' => $result['status'] === 'paid' ? now() : null]);
            if ($result['status'] === 'paid') {
                $this->completeBooking($booking, $payment, $bookingService, $financeService, $delivery, $notifications);
            } elseif ($result['status'] === 'failed') {
                $bookingService->releaseAfterPermanentPaymentFailure($booking);
            }
        } catch (Throwable $exception) {
            $payment->update(['status' => 'failed']);

            throw $exception;
        }

        return response()->json($payment->refresh(), 201);
    }

    private function completeBooking(Booking $booking, Payment $payment, BookingService $bookingService, FinanceService $financeService, TicketDeliveryService $delivery, PassengerJourneyNotificationService $notifications): void
    {
        DB::transaction(function () use ($booking, $payment, $bookingService, $financeService, $delivery, $notifications): void {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $paid = (float) Payment::where('booking_id', $booking->id)->where('status', 'paid')->sum('amount');
            if ($paid < (float) $booking->total) {
                return;
            }

            $booking->update(['status' => 'confirmed', 'payable_until' => null]);
            $booking->passengers()->update(['status' => 'confirmed']);
            $bookingService->issueTickets($booking->load('passengers'), $delivery);
            $financeService->allocateConfirmedBooking($booking->fresh(['trip.company']), $payment);
            $notifications->paymentConfirmed($booking->fresh());
        });
    }

    public function index(Request $request, Booking $booking): JsonResponse
    {
        $allowed = $booking->user_id === $request->user()->id || ($booking->company_id === $request->user()->company_id && $request->user()->can('finance.manage'));
        abort_unless($allowed, 404);

        return response()->json($booking->payments()->latest('id')->get());
    }
}
