<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PlatformResource;
use App\Models\Ticket;
use App\Services\BookingCancellationService;
use App\Services\PassengerJourneyNotificationService;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingCancellationController extends Controller
{
    public function __invoke(Request $request, Booking $booking, BookingCancellationService $cancellations, PricingService $pricing, PassengerJourneyNotificationService $notifications): JsonResponse
    {
        $this->authorizeBooking($request, $booking);
        abort_unless(in_array($booking->status, ['confirmed', 'pending_payment'], true), 409, 'This booking cannot be cancelled.');
        $validated = $request->validate(['passenger_ids' => ['nullable', 'array', 'min:1'], 'passenger_ids.*' => ['integer', 'distinct'], 'reason' => ['required', 'string', 'max:1000'], 'refund_method' => ['required', 'in:original,wallet,voucher']]);
        $result = DB::transaction(function () use ($request, $booking, $validated, $cancellations, $pricing): array {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->with(['passengers', 'trip.company'])->firstOrFail();
            $passengers = isset($validated['passenger_ids']) ? $booking->passengers->whereIn('id', $validated['passenger_ids']) : $booking->passengers;
            abort_if($passengers->isEmpty() || $passengers->contains(fn ($passenger) => ! in_array($passenger->status, ['confirmed', 'held'], true)), 422, 'One or more selected passengers cannot be cancelled.');
            $quote = $cancellations->quote($booking, $passengers);
            $passengerIds = $passengers->pluck('id');
            $booking->passengers()->whereIn('id', $passengerIds)->update(['status' => 'cancelled', 'trip_id' => null]);
            Ticket::whereIn('booking_passenger_id', $passengerIds)->update(['status' => 'void']);
            if ($booking->passengers()->whereIn('status', ['confirmed', 'held'])->doesntExist()) {
                if ($booking->status === 'pending_payment') {
                    $pricing->restoreCoupon($booking);
                }
                $booking->update(['status' => 'cancelled']);
            } else {
                $booking->update(['status' => 'partially_cancelled']);
            }
            $cancellation = (new PlatformResource)->useModule('cancellations');
            $cancellation->fill(['company_id' => $booking->company_id, 'user_id' => $request->user()->id, 'code' => $booking->reference, 'name' => 'Booking cancellation', 'status' => 'approved', 'amount' => $quote['cancellation_charge'], 'currency' => $booking->currency, 'data' => ['booking_id' => $booking->id, 'passenger_ids' => $passengerIds->all(), 'reason' => $validated['reason'], 'refund_percent' => $quote['refund_percent']]])->save();
            if ($quote['refund_amount'] > 0) {
                $refund = (new PlatformResource)->useModule('refunds');
                $refund->fill(['company_id' => $booking->company_id, 'user_id' => $request->user()->id, 'code' => $booking->reference, 'name' => 'Booking refund', 'status' => 'pending', 'amount' => $quote['refund_amount'], 'currency' => $booking->currency, 'data' => ['booking_id' => $booking->id, 'method' => $validated['refund_method']]])->save();
            }

            return ['booking_status' => $booking->refresh()->status, ...$quote];
        });
        $notifications->bookingCancelled($booking->fresh(), $result);

        return response()->json($result, 201);
    }

    private function authorizeBooking(Request $request, Booking $booking): void
    {
        $allowed = $booking->user_id === $request->user()->id || ($booking->company_id === $request->user()->company_id && $request->user()->can('bookings.manage'));
        abort_unless($allowed, 404);
    }
}
