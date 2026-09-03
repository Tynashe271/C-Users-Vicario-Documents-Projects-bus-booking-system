<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PlatformResource;
use App\Models\SeatLock;
use App\Models\Ticket;
use App\Models\Trip;
use App\Services\PassengerJourneyNotificationService;
use App\Services\PricingService;
use App\Services\TicketDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingRescheduleController extends Controller
{
    public function __invoke(Request $request, Booking $booking, PricingService $pricing, TicketDeliveryService $delivery, PassengerJourneyNotificationService $notifications): JsonResponse
    {
        $allowed = $booking->user_id === $request->user()->id || ($booking->company_id === $request->user()->company_id && $request->user()->can('bookings.manage'));
        abort_unless($allowed, 404);
        abort_unless($booking->status === 'confirmed', 409, 'Only confirmed bookings can be rescheduled.');
        $validated = $request->validate(['trip_id' => ['required', 'exists:trips,id'], 'lock_token' => ['required', 'uuid'], 'seats' => ['required', 'array', 'min:1'], 'seats.*.passenger_id' => ['required', 'integer', 'distinct'], 'seats.*.seat_id' => ['required', 'integer', 'distinct']]);
        $targetTrip = Trip::findOrFail($validated['trip_id']);
        abort_unless($targetTrip->id !== $booking->trip_id && $targetTrip->company_id === $booking->company_id && $targetTrip->route_id === $booking->trip->route_id && $targetTrip->departs_at->isFuture() && in_array($targetTrip->status, ['published', 'available', 'almost_full'], true), 422, 'The target trip is not eligible.');
        $result = DB::transaction(function () use ($request, $booking, $targetTrip, $validated, $pricing, $delivery): array {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->with('passengers')->firstOrFail();
            abort_unless(count($validated['seats']) === $booking->passengers->where('status', 'confirmed')->count(), 422, 'All active passengers must be assigned to the new trip.');
            $locks = SeatLock::where('token', $validated['lock_token'])->where('trip_id', $targetTrip->id)->where('user_id', $request->user()->id)->where('expires_at', '>', now())->lockForUpdate()->get();
            abort_unless($locks->pluck('seat_id')->sort()->values()->all() === collect($validated['seats'])->pluck('seat_id')->sort()->values()->all(), 422, 'The seat hold does not match this reschedule.');
            $passengerInputs = $booking->passengers->where('status', 'confirmed')->values()->map(function ($passenger) use ($validated): array {
                $assignment = collect($validated['seats'])->firstWhere('passenger_id', $passenger->id);

                return ['type' => $passenger->type, 'seat_id' => $assignment['seat_id']];
            })->all();
            $services = collect(data_get($booking->fare_breakdown, 'services', []))->pluck('code')->all();
            $quote = $pricing->quote($targetTrip, $passengerInputs, $services);
            $fareByPassenger = $booking->passengers->where('status', 'confirmed')->values()->mapWithKeys(fn ($passenger, int $index): array => [$passenger->id => $quote['passenger_fares'][$index]]);
            foreach ($validated['seats'] as $assignment) {
                $passenger = $booking->passengers->firstWhere('id', $assignment['passenger_id']);
                abort_unless($passenger, 422, 'A passenger does not belong to this booking.');
                $passenger->update(['trip_id' => $targetTrip->id, 'seat_id' => $assignment['seat_id'], 'fare' => $fareByPassenger[$passenger->id]]);
            }
            $difference = round($quote['total'] - (float) $booking->total, 2);
            Ticket::whereIn('booking_passenger_id', $booking->passengers->pluck('id'))->get()->each(fn (Ticket $ticket) => $ticket->update(['qr_token' => hash('sha256', Str::uuid()), 'status' => $difference > 0 ? 'pending_payment' : 'active']));
            $booking->update(['trip_id' => $targetTrip->id, 'subtotal' => $quote['subtotal'], 'discount' => $quote['discount'], 'taxes' => $quote['taxes'], 'fees' => $quote['fees'], 'platform_fee' => $quote['platform_fee'], 'total' => $quote['total'], 'fare_breakdown' => $quote, 'status' => $difference > 0 ? 'pending_payment' : 'confirmed', 'payable_until' => $difference > 0 ? $locks->min('expires_at') : null]);
            $history = (new PlatformResource)->useModule('booking_seats');
            $history->fill(['company_id' => $booking->company_id, 'user_id' => $request->user()->id, 'code' => $booking->reference, 'name' => 'Booking rescheduled', 'status' => 'completed', 'amount' => $difference, 'currency' => $booking->currency, 'data' => ['new_trip_id' => $targetTrip->id, 'assignments' => $validated['seats']]])->save();
            if ($difference < 0) {
                $refund = (new PlatformResource)->useModule('refunds');
                $refund->fill(['company_id' => $booking->company_id, 'user_id' => $request->user()->id, 'code' => $booking->reference, 'name' => 'Reschedule fare difference', 'status' => 'pending', 'amount' => abs($difference), 'currency' => $booking->currency, 'data' => ['booking_id' => $booking->id, 'method' => 'original']])->save();
            }
            $locks->each->delete();
            if ($difference <= 0) {
                $delivery->queue($booking->refresh()->load('passengers.ticket', 'trip'));
            }

            return ['booking' => $booking->refresh()->load('passengers.seat', 'passengers.ticket', 'trip.route.origin', 'trip.route.destination'), 'fare_difference' => $difference];
        });
        $notifications->bookingRescheduled($booking->fresh(), $result['fare_difference']);

        return response()->json($result);
    }
}
