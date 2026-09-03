<?php

namespace App\GraphQL;

use App\Models\Trip;
use Illuminate\Support\Collection;

class TripSearchResolver
{
    /** @param array{origin_terminal_id:int, destination_terminal_id:int, date:string} $arguments */
    public function __invoke(mixed $root, array $arguments): Collection
    {
        return Trip::query()
            ->with(['route.origin', 'route.destination', 'bus.seats'])
            ->whereHas('route', fn ($query) => $query->where('origin_terminal_id', $arguments['origin_terminal_id'])->where('destination_terminal_id', $arguments['destination_terminal_id'])->where('active', true))
            ->whereDate('departs_at', $arguments['date'])
            ->whereIn('status', ['published', 'available', 'almost_full'])
            ->limit(100)
            ->get();
    }
}
