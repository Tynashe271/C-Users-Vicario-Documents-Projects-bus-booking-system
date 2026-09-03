<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\SeatLock;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingRescheduleOptionController extends Controller
{
    public function __invoke(Request $request, Booking $booking): JsonResponse
    {
        abort_unless($booking->user_id === $request->user()->id || ($booking->company_id === $request->user()->company_id && $request->user()->can('bookings.manage')), 404);
        abort_unless($booking->status === 'confirmed', 409, 'Only confirmed bookings can be rescheduled.');
        $validated = $request->validate(['date' => ['nullable', 'date', 'after_or_equal:today']]);
        $passengerCount = $booking->passengers()->where('status', 'confirmed')->count();
        $trips = Trip::query()->where('company_id', $booking->company_id)->where('route_id', $booking->trip->route_id)->whereKeyNot($booking->trip_id)
            ->whereIn('status', ['published', 'available', 'almost_full'])->where('departs_at', '>', now())
            ->when($validated['date'] ?? null, fn ($query, $date) => $query->whereDate('departs_at', $date))
            ->with(['company:id,name', 'route.origin', 'route.destination', 'bus.seats'])->orderBy('departs_at')->limit(30)->get();
        $tripIds = $trips->pluck('id');
        $occupied = BookingPassenger::whereIn('trip_id', $tripIds)->where(function ($query): void {
            $query->where('status', 'confirmed')->orWhere(function ($held): void {
                $held->where('status', 'held')->whereHas('booking', fn ($booking) => $booking->where('payable_until', '>', now()));
            });
        })->get(['trip_id', 'seat_id'])
            ->concat(SeatLock::whereIn('trip_id', $tripIds)->where('expires_at', '>', now())->get(['trip_id', 'seat_id']))->groupBy('trip_id');
        $options = $trips->map(function (Trip $trip) use ($occupied): Trip {
            $unavailable = $occupied->get($trip->id, collect())->pluck('seat_id')->unique();
            $trip->bus->seats->each(fn ($seat) => $seat->setAttribute('availability', $unavailable->contains($seat->id) ? 'occupied' : 'available'));
            $trip->setAttribute('available_seats', $trip->bus->seats->where('availability', 'available')->count());

            return $trip;
        })->where('available_seats', '>=', $passengerCount)->values();

        return response()->json(['data' => $options, 'passenger_count' => $passengerCount]);
    }
}
