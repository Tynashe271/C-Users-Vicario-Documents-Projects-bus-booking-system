<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingPassenger;
use App\Models\PlatformResource;
use App\Models\SeatLock;
use App\Models\Terminal;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class TripController extends Controller
{
    public function terminals(Request $request): JsonResponse
    {
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'country' => ['nullable', 'string', 'size:2']]);
        $terminals = Terminal::query()
            ->when($validated['country'] ?? null, fn (Builder $query, string $country): Builder => $query->where('country', strtoupper($country)))
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(fn (Builder $nested): Builder => $nested->where('name', 'like', "%{$search}%")->orWhere('city', 'like', "%{$search}%"));
            })->orderBy('country')->orderBy('city')->orderBy('name')->limit(100)->get();

        return response()->json(['data' => $terminals]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origin_terminal_id' => ['required', 'integer', 'exists:terminals,id', 'different:destination_terminal_id'],
            'destination_terminal_id' => ['required', 'integer', 'exists:terminals,id'],
            'date' => ['required', 'date'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'bus_class' => ['nullable', 'string', 'max:50'],
            'min_price' => ['nullable', 'numeric', 'min:0'], 'max_price' => ['nullable', 'numeric', 'min:0', Rule::when($request->filled('min_price'), ['gte:min_price'])],
            'departure_from' => ['nullable', 'date_format:H:i'], 'departure_to' => ['nullable', 'date_format:H:i'],
            'arrival_from' => ['nullable', 'date_format:H:i'], 'arrival_to' => ['nullable', 'date_format:H:i'],
            'max_duration' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'refund_policy' => ['nullable', 'string', 'max:50'], 'min_rating' => ['nullable', 'numeric', 'between:0,5'],
            'minimum_seats' => ['nullable', 'integer', 'min:1', 'max:50'],
            'amenities' => ['nullable', 'array', 'max:20'], 'amenities.*' => ['string', 'max:50', 'distinct'],
            'sort' => ['nullable', 'in:price_asc,price_desc,departure_asc,departure_desc,arrival_asc,duration_asc,rating_desc,availability_desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $trips = $this->searchTrips($validated);

        $trips = $this->decorateTrips($trips)->filter(function (Trip $trip) use ($validated): bool {
            $amenities = collect($trip->bus->amenities ?? []);

            return $trip->available_seats >= ($validated['minimum_seats'] ?? 0)
                && $trip->duration_minutes <= ($validated['max_duration'] ?? PHP_INT_MAX)
                && $trip->operator_rating >= ($validated['min_rating'] ?? 0)
                && (! isset($validated['refund_policy']) || $trip->refund_policy['code'] === $validated['refund_policy'])
                && collect($validated['amenities'] ?? [])->every(fn (string $amenity): bool => $amenities->contains($amenity));
        });

        $trips = match ($validated['sort'] ?? 'departure_asc') {
            'price_asc' => $trips->sortBy('base_fare', SORT_NUMERIC), 'price_desc' => $trips->sortByDesc('base_fare', SORT_NUMERIC),
            'departure_desc' => $trips->sortByDesc('departs_at'), 'arrival_asc' => $trips->sortBy('arrives_at'),
            'duration_asc' => $trips->sortBy('duration_minutes', SORT_NUMERIC), 'rating_desc' => $trips->sortByDesc('operator_rating', SORT_NUMERIC),
            'availability_desc' => $trips->sortByDesc('available_seats', SORT_NUMERIC), default => $trips->sortBy('departs_at'),
        };
        $perPage = $validated['per_page'] ?? 20;
        $page = $validated['page'] ?? 1;
        $paginator = new LengthAwarePaginator($trips->forPage($page, $perPage)->values(), $trips->count(), $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return response()->json($paginator);
    }

    /**
     * The expensive, slowly-changing part of a search — which trips match the route/date/price/
     * class filters — cached briefly under the filters that actually shape the SQL. Deliberately
     * NOT cached: decorateTrips()'s seat-lock-derived available_seats, computed fresh below on
     * every request — caching that would risk showing a seat as free after it's already held,
     * undermining the seat-lock correctness work in BookingService.
     *
     * @param  array<string, mixed>  $validated
     * @return Collection<int, Trip>
     */
    private function searchTrips(array $validated): Collection
    {
        $cacheKey = 'trip-search:'.md5(json_encode([
            'origin' => $validated['origin_terminal_id'], 'destination' => $validated['destination_terminal_id'], 'date' => $validated['date'],
            'company_id' => $validated['company_id'] ?? null, 'bus_class' => $validated['bus_class'] ?? null,
            'min_price' => $validated['min_price'] ?? null, 'max_price' => $validated['max_price'] ?? null,
            'departure_from' => $validated['departure_from'] ?? null, 'departure_to' => $validated['departure_to'] ?? null,
            'arrival_from' => $validated['arrival_from'] ?? null, 'arrival_to' => $validated['arrival_to'] ?? null,
        ], JSON_THROW_ON_ERROR));

        return Cache::remember($cacheKey, 30, fn () => Trip::query()
            ->with(['company:id,name,slug,settings', 'route.origin', 'route.destination', 'bus.seats'])
            ->whereHas('route', fn (Builder $route): Builder => $route->where('origin_terminal_id', $validated['origin_terminal_id'])->where('destination_terminal_id', $validated['destination_terminal_id'])->where('active', true))
            ->whereDate('departs_at', $validated['date'])->whereIn('status', ['published', 'available', 'almost_full'])
            ->when($validated['company_id'] ?? null, fn (Builder $query, int $id): Builder => $query->where('company_id', $id))
            ->when($validated['bus_class'] ?? null, fn (Builder $query, string $class): Builder => $query->whereHas('bus', fn (Builder $bus): Builder => $bus->where('class', $class)))
            ->when(isset($validated['min_price']), fn (Builder $query): Builder => $query->where('base_fare', '>=', $validated['min_price']))
            ->when(isset($validated['max_price']), fn (Builder $query): Builder => $query->where('base_fare', '<=', $validated['max_price']))
            ->when($validated['departure_from'] ?? null, fn (Builder $query, string $time): Builder => $query->whereTime('departs_at', '>=', $time))
            ->when($validated['departure_to'] ?? null, fn (Builder $query, string $time): Builder => $query->whereTime('departs_at', '<=', $time))
            ->when($validated['arrival_from'] ?? null, fn (Builder $query, string $time): Builder => $query->whereTime('arrives_at', '>=', $time))
            ->when($validated['arrival_to'] ?? null, fn (Builder $query, string $time): Builder => $query->whereTime('arrives_at', '<=', $time))->get());
    }

    public function show(Trip $trip): JsonResponse
    {
        $trip->load(['company:id,name,slug,settings', 'route.origin', 'route.destination', 'bus.seats']);
        $trip = $this->decorateTrips(collect([$trip]))->firstOrFail();
        $stops = (new PlatformResource)->useModule('trip_stops')->newQuery()->where('company_id', $trip->company_id)->where('status', 'active')->orderBy('starts_at')->get()
            ->filter(fn (PlatformResource $stop): bool => (int) data_get($stop->data, 'trip_id') === $trip->id)->values();
        $trip->setAttribute('intermediate_stops', $stops);

        return response()->json($trip);
    }

    /** @param Collection<int, Trip> $trips */
    public function decorateTrips(Collection $trips): Collection
    {
        $tripIds = $trips->pluck('id');
        $unavailable = BookingPassenger::query()->whereIn('trip_id', $tripIds)->where(function (Builder $query): void {
            $query->where('status', 'confirmed')->orWhere(function (Builder $held): void {
                $held->where('status', 'held')->whereHas('booking', fn (Builder $booking): Builder => $booking->where('payable_until', '>', now()));
            });
        })->get(['trip_id', 'seat_id'])
            ->concat(SeatLock::query()->whereIn('trip_id', $tripIds)->where('expires_at', '>', now())->get(['trip_id', 'seat_id']))->groupBy('trip_id');
        $ratings = (new PlatformResource)->useModule('reviews')->newQuery()->whereIn('company_id', $trips->pluck('company_id')->unique())->where('status', 'active')->get(['company_id', 'amount'])
            ->groupBy('company_id')->map(fn (Collection $reviews): float => round((float) $reviews->avg('amount'), 1));

        return $trips->each(function (Trip $trip) use ($unavailable, $ratings): void {
            $occupied = $unavailable->get($trip->id, collect())->pluck('seat_id')->unique();
            $layoutColumns = in_array($trip->bus->class, ['sleeper', 'minibus'], true) ? 3 : 4;
            $trip->bus->seats->each(function ($seat) use ($occupied, $layoutColumns): void {
                $seatLetter = strtoupper(substr($seat->number, -1));
                $inferredPosition = in_array($seatLetter, $layoutColumns === 3 ? ['A', 'C'] : ['A', 'D'], true) ? 'window' : 'aisle';
                $seat->setAttribute('position', in_array($seat->type, ['window', 'aisle'], true) ? $seat->type : $inferredPosition);
                $seat->setAttribute('availability', $occupied->contains($seat->id) ? 'occupied' : 'available');
            });
            $trip->setAttribute('available_seats', $trip->bus->seats->where('availability', 'available')->count());
            $trip->setAttribute('duration_minutes', $trip->departs_at->diffInMinutes($trip->arrives_at));
            $trip->setAttribute('operator_rating', $ratings->get($trip->company_id, (float) data_get($trip->company->settings, 'rating', 0)));
            $trip->setAttribute('luggage_allowance', data_get($trip->company->settings, 'luggage_allowance', 'Contact the operator for luggage limits.'));
            $refundPolicy = data_get($trip->company->settings, 'refund_policy', ['code' => 'standard', 'label' => 'Standard refund policy']);
            $trip->setAttribute('refund_policy', is_array($refundPolicy) ? array_replace(['code' => 'standard', 'label' => 'Standard refund policy'], $refundPolicy) : ['code' => str($refundPolicy)->slug()->toString(), 'label' => $refundPolicy]);
            $trip->setAttribute('cancellation_policy', data_get($trip->company->settings, 'cancellation_policy', []));
            $trip->bus->setAttribute('seat_layout', [
                'columns' => $layoutColumns,
                'class' => $trip->bus->class,
            ]);
        });
    }
}
