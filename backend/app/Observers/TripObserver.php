<?php

namespace App\Observers;

use App\Models\Trip;
use App\Services\LoyaltyService;
use App\Services\PassengerJourneyNotificationService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class TripObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly PassengerJourneyNotificationService $notifications, private readonly LoyaltyService $loyalty) {}

    /**
     * Handle the Trip "created" event.
     */
    public function created(Trip $trip): void
    {
        //
    }

    /**
     * Handle the Trip "updated" event.
     */
    public function updated(Trip $trip): void
    {
        $this->notifications->tripChanged($trip);
        if ($trip->wasChanged('status') && $trip->status === 'completed') {
            $trip->bookings()->whereIn('status', ['confirmed', 'completed'])->get()->each(fn ($booking) => $this->loyalty->awardTrip($booking));
        }
    }

    /**
     * Handle the Trip "deleted" event.
     */
    public function deleted(Trip $trip): void
    {
        //
    }

    /**
     * Handle the Trip "restored" event.
     */
    public function restored(Trip $trip): void
    {
        //
    }

    /**
     * Handle the Trip "force deleted" event.
     */
    public function forceDeleted(Trip $trip): void
    {
        //
    }
}
