<?php

namespace App\GraphQL;

use App\Http\Controllers\Api\TripController;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TripSearchResolver
{
    /** @param array{origin_terminal_id:int, destination_terminal_id:int, date:string, company_id?:int, bus_class?:string, min_price?:float, max_price?:float, minimum_seats?:int, sort?:string} $arguments */
    public function __invoke(mixed $root, array $arguments): Collection
    {
        $trips = Trip::query()
            ->with(['company:id,name,slug,settings', 'route.origin', 'route.destination', 'bus.seats'])
            ->whereHas('route', fn (Builder $query): Builder => $query->where('origin_terminal_id', $arguments['origin_terminal_id'])->where('destination_terminal_id', $arguments['destination_terminal_id'])->where('active', true))
            ->whereDate('departs_at', $arguments['date'])
            ->whereIn('status', ['published', 'available', 'almost_full'])
            ->when($arguments['company_id'] ?? null, fn (Builder $query, int $id): Builder => $query->where('company_id', $id))
            ->when($arguments['bus_class'] ?? null, fn (Builder $query, string $class): Builder => $query->whereHas('bus', fn (Builder $bus): Builder => $bus->where('class', $class)))
            ->when(isset($arguments['min_price']), fn (Builder $query): Builder => $query->where('base_fare', '>=', $arguments['min_price']))
            ->when(isset($arguments['max_price']), fn (Builder $query): Builder => $query->where('base_fare', '<=', $arguments['max_price']))
            ->limit(100)->get();

        // Reuses TripController's own decoration exactly, so a GraphQL search never disagrees with
        // REST's /trips about seat availability, duration, or rating for the same trip.
        $trips = (new TripController)->decorateTrips($trips)
            ->filter(fn (Trip $trip): bool => $trip->available_seats >= ($arguments['minimum_seats'] ?? 0));

        return match ($arguments['sort'] ?? 'departure_asc') {
            'price_asc' => $trips->sortBy('base_fare', SORT_NUMERIC)->values(),
            'price_desc' => $trips->sortByDesc('base_fare', SORT_NUMERIC)->values(),
            'availability_desc' => $trips->sortByDesc('available_seats', SORT_NUMERIC)->values(),
            default => $trips->sortBy('departs_at')->values(),
        };
    }
}
