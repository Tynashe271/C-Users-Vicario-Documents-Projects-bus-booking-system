<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\TrackingLink;
use App\Models\Trip;
use App\Models\VehicleLocation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TrackingController extends Controller
{
    public function update(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeOperations($request, $trip, true);
        abort_unless(in_array($trip->status, ['boarding', 'departed', 'delayed'], true), 409, 'Tracking is available only for active trips.');
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180'],
            'speed_kph' => ['required', 'numeric', 'between:0,180'], 'heading' => ['nullable', 'integer', 'between:0,359'], 'accuracy_m' => ['nullable', 'numeric', 'between:0,5000'],
            'recorded_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
        ]);
        $recordedAt = CarbonImmutable::parse($validated['recorded_at']);
        abort_if($recordedAt->isBefore(now()->subHours(24)), 422, 'Location updates cannot be more than 24 hours old.');
        $trip->loadMissing(['route.origin', 'route.destination']);
        $previous = $trip->locations()->latest('recorded_at')->first();
        $nearTerminal = collect([$trip->route->origin, $trip->route->destination])->first(fn ($terminal) => $terminal->latitude !== null && $this->distanceKm((float) $validated['latitude'], (float) $validated['longitude'], (float) $terminal->latitude, (float) $terminal->longitude) <= 0.5);
        $unexpectedStop = (float) $validated['speed_kph'] < 2 && $nearTerminal === null && $previous && $previous->speed_kph < 2 && $previous->recorded_at->diffInMinutes($recordedAt) >= 5;
        $location = $trip->locations()->create([...$validated, 'company_id' => $trip->company_id, 'user_id' => $request->user()->id, 'bus_id' => $trip->bus_id, 'name' => 'GPS update', 'status' => 'recorded', 'recorded_at' => $recordedAt, 'near_terminal_id' => $nearTerminal?->id, 'unexpected_stop' => $unexpectedStop, 'code' => Str::uuid()]);

        return response()->json($this->payload($trip, $location, false), 201);
    }

    public function history(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeOperations($request, $trip);
        $validated = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after:from'], 'limit' => ['nullable', 'integer', 'min:1', 'max:1000']]);
        $locations = $trip->locations()->when($validated['from'] ?? null, fn ($query, $from) => $query->where('recorded_at', '>=', $from))->when($validated['to'] ?? null, fn ($query, $to) => $query->where('recorded_at', '<=', $to))->latest('recorded_at')->limit($validated['limit'] ?? 250)->get();

        return response()->json(['trip_id' => $trip->id, 'locations' => $locations]);
    }

    public function createLink(Request $request, Trip $trip): JsonResponse
    {
        $validated = $request->validate(['booking_id' => ['nullable', 'integer', 'exists:bookings,id'], 'expires_in_hours' => ['nullable', 'integer', 'min:1', 'max:168'], 'privacy_precision' => ['nullable', 'in:approximate,precise']]);
        $booking = isset($validated['booking_id']) ? Booking::findOrFail($validated['booking_id']) : null;
        $isPassenger = $booking && $booking->trip_id === $trip->id && $booking->user_id === $request->user()->id;
        $isOperator = $request->user()->company_id === $trip->company_id && $request->user()->can('trips.manage');
        abort_unless($isPassenger || $isOperator, 404);
        $token = Str::random(48);
        TrackingLink::create(['company_id' => $trip->company_id, 'user_id' => $request->user()->id, 'trip_id' => $trip->id, 'booking_id' => $booking?->id, 'code' => Str::uuid(), 'name' => 'Shared trip tracking', 'status' => 'active', 'token_hash' => hash('sha256', $token), 'privacy_precision' => $validated['privacy_precision'] ?? 'approximate', 'ends_at' => now()->addHours($validated['expires_in_hours'] ?? 24)]);

        return response()->json(['token' => $token, 'url' => url('/track/'.$token), 'expires_at' => now()->addHours($validated['expires_in_hours'] ?? 24)->toIso8601String()], 201);
    }

    public function publicShow(string $token): JsonResponse
    {
        $link = TrackingLink::query()->where('token_hash', hash('sha256', $token))->where('status', 'active')->whereNull('revoked_at')->where('ends_at', '>', now())->with(['trip.route.origin', 'trip.route.destination', 'trip.company:id,name'])->firstOrFail();
        $location = $link->trip->locations()->latest('recorded_at')->first();
        abort_unless($location, 404, 'No tracking information is available yet.');

        return response()->json($this->payload($link->trip, $location, $link->privacy_precision !== 'precise'));
    }

    private function authorizeOperations(Request $request, Trip $trip, bool $allowDriver = false): void
    {
        $allowedRole = $allowDriver && in_array($request->user()->role, ['driver', 'conductor'], true);
        abort_unless($request->user()->company_id === $trip->company_id && ($allowedRole || $request->user()->can('trips.manage')), 404);
    }

    /** @return array<string, mixed> */
    private function payload(Trip $trip, VehicleLocation $location, bool $approximate): array
    {
        $trip->loadMissing(['route.origin', 'route.destination', 'company:id,name']);
        $destination = $trip->route->destination;
        $remaining = $destination->latitude === null ? null : $this->distanceKm($location->latitude, $location->longitude, (float) $destination->latitude, (float) $destination->longitude);
        $etaMinutes = $remaining === null ? null : (int) ceil($remaining / max($location->speed_kph, 30) * 60);
        $alerts = collect()
            ->when($trip->status === 'delayed', fn ($items) => $items->push('The trip is delayed. The arrival estimate reflects the latest location.'))
            ->when($location->route_deviation, fn ($items) => $items->push('The bus has deviated from the planned route.'))
            ->when($location->unexpected_stop, fn ($items) => $items->push('The bus has made an unexpected stop.'))
            ->values()->all();

        return ['trip_id' => $trip->id, 'operator' => $trip->company->name, 'route' => ['origin' => $trip->route->origin->name, 'destination' => $destination->name], 'status' => $trip->status, 'location' => ['latitude' => round($location->latitude, $approximate ? 3 : 6), 'longitude' => round($location->longitude, $approximate ? 3 : 6), 'speed_kph' => $approximate ? null : $location->speed_kph, 'heading' => $location->heading, 'accuracy_m' => $location->accuracy_m, 'recorded_at' => $location->recorded_at->toIso8601String()], 'distance_remaining_km' => $remaining === null ? null : round($remaining, 1), 'estimated_arrival_minutes' => $etaMinutes, 'estimated_arrival_at' => $etaMinutes === null ? null : now()->addMinutes($etaMinutes)->toIso8601String(), 'near_terminal' => $location->near_terminal_id !== null, 'unexpected_stop' => $location->unexpected_stop, 'route_deviation' => $location->route_deviation, 'alerts' => $alerts];
    }

    private function distanceKm(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $earthRadius = 6371;
        $latitudeDelta = deg2rad($latitudeB - $latitudeA);
        $longitudeDelta = deg2rad($longitudeB - $longitudeA);
        $a = sin($latitudeDelta / 2) ** 2 + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($longitudeDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
