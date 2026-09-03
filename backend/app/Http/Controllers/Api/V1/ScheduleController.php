<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bus;
use App\Models\RecurringSchedule;
use App\Models\TransportRoute;
use App\Services\ScheduleExpansionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('trips.manage'), 403);

        return response()->json(RecurringSchedule::where('company_id', $request->user()->company_id)->latest('id')->paginate(25));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, ScheduleExpansionService $expansion): JsonResponse
    {
        abort_unless($request->user()->can('trips.manage') && $request->user()->company_id, 403);
        $validated = $request->validate(['name' => ['required', 'string', 'max:255'], 'route_id' => ['required', 'integer'], 'bus_id' => ['required', 'integer'], 'days_of_week' => ['required', 'array', 'min:1'], 'days_of_week.*' => ['integer', 'between:1,7', 'distinct'], 'departure_time' => ['required', 'date_format:H:i'], 'timezone' => ['required', 'timezone'], 'starts_on' => ['required', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'], 'fare' => ['required', 'numeric', 'min:0'], 'currency' => ['required', 'string', 'size:3'], 'generate_days' => ['sometimes', 'integer', 'between:1,365']]);
        abort_unless(TransportRoute::whereKey($validated['route_id'])->where('company_id', $request->user()->company_id)->exists(), 422, 'The route does not belong to this company.');
        $bus = Bus::whereKey($validated['bus_id'])->where('company_id', $request->user()->company_id)->where('status', 'available')->first();
        abort_unless($bus?->hasApprovedOperationalDocuments(), 422, 'The bus is unavailable or does not have approved, current insurance and permit documents.');
        $schedule = RecurringSchedule::create(['company_id' => $request->user()->company_id, 'user_id' => $request->user()->id, 'code' => str()->uuid(), 'name' => $validated['name'], 'status' => 'active', 'amount' => $validated['fare'], 'currency' => strtoupper($validated['currency']), 'starts_at' => $validated['starts_on'], 'ends_at' => $validated['ends_on'] ?? null, 'data' => ['route_id' => $validated['route_id'], 'bus_id' => $validated['bus_id'], 'days_of_week' => $validated['days_of_week'], 'departure_time' => $validated['departure_time'], 'timezone' => $validated['timezone']]]);
        $created = $expansion->expand($schedule, CarbonImmutable::now()->addDays($validated['generate_days'] ?? 90));

        return response()->json(['schedule' => $schedule, 'trips_created' => $created], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, RecurringSchedule $schedule): JsonResponse
    {
        abort_unless($request->user()->can('trips.manage') && $schedule->company_id === $request->user()->company_id, 404);

        return response()->json($schedule);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, RecurringSchedule $schedule): JsonResponse
    {
        abort_unless($request->user()->can('trips.manage') && $schedule->company_id === $request->user()->company_id, 404);
        $validated = $request->validate(['name' => ['sometimes', 'string', 'max:255'], 'status' => ['sometimes', 'in:active,paused,cancelled'], 'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:today']]);
        $schedule->update($validated);

        return response()->json($schedule->refresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, RecurringSchedule $schedule): JsonResponse
    {
        abort_unless($request->user()->can('trips.manage') && $schedule->company_id === $request->user()->company_id, 404);
        $schedule->update(['status' => 'cancelled']);

        return response()->json(status: 204);
    }
}
