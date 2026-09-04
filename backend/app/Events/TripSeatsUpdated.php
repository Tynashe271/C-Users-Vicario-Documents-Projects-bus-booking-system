<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pushed over Reverb (see config/broadcasting.php) whenever TripOccupancyService::sync()
 * recomputes a trip's occupancy, so anyone with that trip's page open sees seat availability
 * update live instead of only on their next page load. Broadcast immediately (not queued) —
 * a delayed "real-time" update isn't real-time. Deliberately doesn't touch
 * TripController::searchTrips()'s short-lived cache: that cache never holds seat-lock-derived
 * data in the first place (see its docblock), so there's nothing here for it to invalidate.
 */
class TripSeatsUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $tripId, public int $availableSeats, public string $status) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new Channel("trip.{$this->tripId}")];
    }

    public function broadcastAs(): string
    {
        return 'seats.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['trip_id' => $this->tripId, 'available_seats' => $this->availableSeats, 'status' => $this->status];
    }
}
