<?php

namespace App\Services;

use App\Models\BookingPassenger;
use App\Models\Trip;

class TripOccupancyService
{
    /** Trip statuses this service is allowed to move a trip between as seats fill up. */
    private const BOOKING_OPEN_STATUSES = ['published', 'available', 'almost_full', 'fully_booked'];

    /**
     * Recompute a trip's occupancy-driven status (available / almost full / fully booked)
     * from its confirmed passenger count. Leaves trips outside the normal booking-open
     * lifecycle (draft, boarding, departed, delayed, arrived, completed, cancelled) untouched.
     */
    public function sync(Trip $trip): void
    {
        if (! in_array($trip->status, self::BOOKING_OPEN_STATUSES, true)) {
            return;
        }
        $capacity = $trip->bus?->seat_capacity;
        if (! $capacity) {
            return;
        }
        $confirmed = BookingPassenger::where('trip_id', $trip->id)->where('status', 'confirmed')->count();
        $ratio = $confirmed / $capacity;
        $status = match (true) {
            $ratio >= 1 => 'fully_booked',
            $ratio >= 0.8 => 'almost_full',
            default => 'available',
        };
        if ($status !== $trip->status) {
            $trip->update(['status' => $status]);
        }
    }
}
