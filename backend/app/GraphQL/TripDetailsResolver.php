<?php

namespace App\GraphQL;

use App\Http\Controllers\Api\TripController;
use App\Models\Trip;

class TripDetailsResolver
{
    /** @param array{id:int} $arguments */
    public function __invoke(mixed $root, array $arguments): ?Trip
    {
        $trip = Trip::with(['company:id,name,slug,settings', 'route.origin', 'route.destination', 'bus.seats'])->find($arguments['id']);
        if (! $trip) {
            return null;
        }

        return (new TripController)->decorateTrips(collect([$trip]))->first();
    }
}
