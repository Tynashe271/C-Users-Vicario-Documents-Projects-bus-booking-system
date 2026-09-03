<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bus;
use App\Models\Commission;
use App\Models\PlatformResource;
use App\Models\StaffAssignment;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TripManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['status' => ['nullable', Rule::in($this->statuses())], 'route_id' => ['nullable', 'integer'], 'date' => ['nullable', 'date']]);
        $trips = Trip::query()->where('company_id', $this->companyId($request))
            ->when($validated['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($validated['route_id'] ?? null, fn (Builder $query, int $id): Builder => $query->where('route_id', $id))
            ->when($validated['date'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('departs_at', $date))
            ->withCount(['bookings as confirmed_passengers' => fn (Builder $query) => $query->whereHas('passengers', fn (Builder $passengers) => $passengers->where('status', 'confirmed'))])
            ->with(['route.origin', 'route.destination', 'bus:id,registration_number,seat_capacity,status'])->latest('departs_at')->paginate(25);

        return response()->json($trips);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $statuses = Trip::query()->where('company_id', $companyId)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $today = Trip::query()->where('company_id', $companyId)->whereDate('departs_at', today())->whereNotIn('status', ['cancelled', 'completed'])->count();
        $delayed = Trip::query()->where('company_id', $companyId)->where('status', 'delayed')->with(['route.origin', 'route.destination'])->orderBy('departs_at')->get();

        return response()->json(['by_status' => $statuses, 'departing_today' => $today, 'delayed_trips' => $delayed]);
    }

    /**
     * Trips departing soon that are still well under capacity — a plain threshold query, not a
     * prediction. Useful for a manual call: promote the fare, add a departure reminder push, or
     * consolidate onto another trip.
     */
    public function lowOccupancyAlerts(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $validated = $request->validate(['hours_ahead' => ['sometimes', 'integer', 'between:1,168'], 'threshold_percent' => ['sometimes', 'numeric', 'between:1,100']]);
        $hoursAhead = $validated['hours_ahead'] ?? 48;
        $threshold = $validated['threshold_percent'] ?? 40;
        $trips = Trip::query()->where('company_id', $companyId)->whereIn('status', ['published', 'available', 'almost_full'])
            ->whereBetween('departs_at', [now(), now()->addHours($hoursAhead)])
            ->with(['route.origin', 'route.destination', 'bus:id,seat_capacity,registration_number'])->orderBy('departs_at')->get();

        $alerts = $trips->map(function (Trip $trip): array {
            $capacity = $trip->bus?->seat_capacity ?: 0;
            $confirmed = (int) $trip->bookings()->withCount(['passengers' => fn (Builder $query) => $query->where('status', 'confirmed')])->get()->sum('passengers_count');

            return ['trip_id' => $trip->id, 'departs_at' => $trip->departs_at->toIso8601String(), 'route' => $trip->route?->name, 'bus' => $trip->bus?->registration_number, 'capacity' => $capacity, 'confirmed_passengers' => $confirmed, 'occupancy_percent' => $capacity > 0 ? round($confirmed / $capacity * 100, 2) : 0.0];
        })->filter(fn (array $alert): bool => $alert['occupancy_percent'] < $threshold)->values();

        return response()->json(['threshold_percent' => $threshold, 'hours_ahead' => $hoursAhead, 'trips' => $alerts]);
    }

    public function publish(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeTrip($request, $trip);
        abort_unless($trip->status === 'draft', 409, 'Only draft trips can be published.');
        $bus = Bus::whereKey($trip->bus_id)->where('status', 'available')->first();
        abort_unless($bus?->hasApprovedOperationalDocuments(), 422, 'The assigned bus is unavailable or does not have approved, current insurance and permit documents.');
        $trip->update(['status' => 'published']);

        return response()->json($trip->refresh());
    }

    public function duplicate(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeTrip($request, $trip);
        $validated = $request->validate(['departs_at' => ['required', 'date', 'after:now'], 'bus_id' => ['nullable', Rule::exists('buses', 'id')->where('company_id', $trip->company_id)]]);
        $departsAt = Carbon::parse($validated['departs_at']);
        $duration = $trip->departs_at->diffInMinutes($trip->arrives_at);
        $copy = Trip::create(['company_id' => $trip->company_id, 'route_id' => $trip->route_id, 'bus_id' => $validated['bus_id'] ?? $trip->bus_id, 'schedule_id' => $trip->schedule_id, 'departs_at' => $departsAt, 'arrives_at' => $departsAt->copy()->addMinutes($duration), 'base_fare' => $trip->base_fare, 'currency' => $trip->currency, 'status' => 'draft', 'duplicated_from_trip_id' => $trip->id]);

        return response()->json($copy, 201);
    }

    public function delay(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeFieldAction($request, $trip);
        abort_unless(in_array($trip->status, ['published', 'available', 'almost_full', 'fully_booked', 'boarding', 'delayed'], true), 409, 'This trip can no longer be delayed.');
        $validated = $request->validate(['departs_at' => ['required', 'date', 'after:'.$trip->departs_at->toIso8601String()], 'arrives_at' => ['required', 'date', 'after:departs_at'], 'reason' => ['required', 'string', 'max:1000']]);
        $trip->update(['departs_at' => $validated['departs_at'], 'arrives_at' => $validated['arrives_at'], 'status' => 'delayed', 'delayed_at' => now(), 'delay_reason' => $validated['reason']]);

        return response()->json($trip->refresh());
    }

    public function cancel(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeTrip($request, $trip);
        abort_if(in_array($trip->status, ['departed', 'arrived', 'completed', 'cancelled'], true), 409, 'This trip can no longer be cancelled.');
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        // Cancelling the trip stops it from running and notifies confirmed passengers (via TripObserver).
        // Passenger-level refunds still go through the existing per-booking cancellation endpoint, which
        // computes the applicable refund policy per booking rather than assuming one blanket outcome here.
        $trip->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => $validated['reason']]);

        return response()->json($trip->refresh());
    }

    public function replaceBus(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeTrip($request, $trip);
        abort_if(in_array($trip->status, ['boarding', 'departed', 'arrived', 'completed', 'cancelled'], true), 409, 'The bus can only be replaced before boarding starts.');
        $validated = $request->validate(['bus_id' => ['required', Rule::exists('buses', 'id')->where('company_id', $trip->company_id)]]);
        abort_if((int) $validated['bus_id'] === $trip->bus_id, 422, 'The replacement bus must be different from the current bus.');
        $bus = Bus::whereKey($validated['bus_id'])->where('status', 'available')->first();
        abort_unless($bus?->hasApprovedOperationalDocuments(), 422, 'The replacement bus is unavailable or does not have approved, current insurance and permit documents.');
        $confirmed = $trip->bookings()->whereHas('passengers', fn (Builder $query) => $query->where('status', 'confirmed'))->withCount(['passengers' => fn (Builder $query) => $query->where('status', 'confirmed')])->get()->sum('passengers_count');
        abort_if($bus->seat_capacity < $confirmed, 422, 'The replacement bus does not have enough seats for the passengers already confirmed on this trip.');
        $trip->update(['bus_id' => $bus->id]);

        return response()->json($trip->refresh()->load('bus'));
    }

    public function board(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeFieldAction($request, $trip);
        abort_unless(in_array($trip->status, ['published', 'available', 'almost_full', 'fully_booked', 'delayed'], true), 409, 'This trip is not ready for boarding.');
        DB::transaction(function () use ($trip): void {
            $trip->update(['status' => 'boarding', 'boarding_started_at' => now()]);
            $trip->bus()->update(['status' => 'boarding']);
        });

        return response()->json($trip->refresh());
    }

    public function depart(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeFieldAction($request, $trip);
        abort_unless($trip->status === 'boarding', 409, 'Only a boarding trip can depart.');
        DB::transaction(function () use ($trip): void {
            $trip->update(['status' => 'departed', 'departed_at' => now()]);
            $trip->bus()->update(['status' => 'in_transit']);
        });

        return response()->json($trip->refresh());
    }

    public function arrive(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeFieldAction($request, $trip);
        abort_unless(in_array($trip->status, ['departed', 'delayed'], true), 409, 'Only a departed trip can arrive.');
        $trip->update(['status' => 'arrived', 'arrived_at' => now()]);

        return response()->json($trip->refresh());
    }

    public function complete(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeFieldAction($request, $trip);
        abort_unless($trip->status === 'arrived', 409, 'Only an arrived trip can be completed.');
        DB::transaction(function () use ($trip): void {
            $trip->update(['status' => 'completed', 'completed_at' => now()]);
            $trip->bus()->update(['status' => 'available']);
        });

        return response()->json(['trip' => $trip->refresh(), 'revenue' => $this->calculateRevenue($trip)]);
    }

    public function revenue(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeTrip($request, $trip);

        return response()->json($this->calculateRevenue($trip));
    }

    public function storeExpense(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeTrip($request, $trip);
        $validated = $request->validate(['type' => ['required', Rule::in(['fuel', 'toll', 'driver_allowance', 'terminal_fee', 'maintenance', 'other'])], 'amount' => ['required', 'numeric', 'min:0'], 'currency' => ['sometimes', 'string', 'size:3'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $expense = (new PlatformResource)->useModule('trip_expenses');
        $expense->fill(['company_id' => $trip->company_id, 'user_id' => $request->user()->id, 'code' => $trip->id.':'.now()->format('YmdHis'), 'name' => str($validated['type'])->headline(), 'status' => 'recorded', 'amount' => $validated['amount'], 'currency' => $validated['currency'] ?? $trip->currency, 'data' => ['trip_id' => $trip->id, 'type' => $validated['type'], 'notes' => $validated['notes'] ?? null]]);
        $expense->save();

        return response()->json($expense, 201);
    }

    /** @return array{gross:float, platform_fee:float, operator_revenue:float, expenses:float, net_revenue:float, currency:string} */
    private function calculateRevenue(Trip $trip): array
    {
        $bookingIds = $trip->bookings()->whereIn('status', ['confirmed', 'completed', 'partially_cancelled'])->pluck('id');
        $gross = (float) $trip->bookings()->whereKey($bookingIds)->sum('total');
        $platformFee = (float) $trip->bookings()->whereKey($bookingIds)->sum('platform_fee');
        $operatorRevenue = (float) Commission::whereIn('booking_id', $bookingIds)->sum('operator_amount');
        $expenses = (float) (new PlatformResource)->useModule('trip_expenses')->newQuery()->where('company_id', $trip->company_id)->where('status', 'recorded')
            ->get()->filter(fn (PlatformResource $expense): bool => (int) data_get($expense->data, 'trip_id') === $trip->id)->sum('amount');

        return ['gross' => round($gross, 2), 'platform_fee' => round($platformFee, 2), 'operator_revenue' => round($operatorRevenue, 2), 'expenses' => round($expenses, 2), 'net_revenue' => round($operatorRevenue - $expenses, 2), 'currency' => $trip->currency];
    }

    private function companyId(Request $request): int
    {
        abort_unless($request->user()->can('trips.manage') && $request->user()->company_id, 403);

        return $request->user()->company_id;
    }

    private function authorizeTrip(Request $request, Trip $trip): void
    {
        abort_unless($trip->company_id === $this->companyId($request), 404);
    }

    /**
     * Boarding/departure/arrival/completion/delay are the trip-status updates the driver app
     * itself needs to send from the road, not just something an operations manager does from
     * the back office. Operations managers (trips.manage) can act on any of the company's trips;
     * a driver or conductor (boarding.manage only) may act only on a trip they are assigned to.
     */
    private function authorizeFieldAction(Request $request, Trip $trip): void
    {
        $user = $request->user();
        abort_unless($user->company_id === $trip->company_id, 404);
        if ($user->can('trips.manage')) {
            return;
        }
        abort_unless($user->can('boarding.manage'), 403);
        $assigned = StaffAssignment::query()->where('trip_id', $trip->id)->whereIn('duty_role', ['driver', 'conductor'])->whereIn('status', ['assigned', 'checked_in'])
            ->whereHas('employee', fn (Builder $query) => $query->where('user_id', $user->id))->exists();
        abort_unless($assigned, 403, 'You are not assigned to this trip.');
    }

    /** @return list<string> */
    private function statuses(): array
    {
        return ['draft', 'published', 'available', 'almost_full', 'fully_booked', 'boarding', 'departed', 'delayed', 'arrived', 'completed', 'cancelled'];
    }
}
