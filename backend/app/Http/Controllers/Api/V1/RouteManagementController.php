<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PlatformResource;
use App\Models\RouteStop;
use App\Models\TransportRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RouteManagementController extends Controller
{
    public function index(Request $request, TransportRoute $route): JsonResponse
    {
        $this->authorizeRoute($request, $route);

        return response()->json($route->stops()->with('terminal')->get());
    }

    public function store(Request $request, TransportRoute $route): JsonResponse
    {
        $this->authorizeRoute($request, $route);
        $validated = $request->validate($this->rules($route));
        $stop = RouteStop::create([...$validated, 'route_id' => $route->id, 'company_id' => $route->company_id, 'code' => "route-{$route->id}-stop-{$validated['sequence']}", 'name' => 'Route stop', 'status' => 'active']);

        return response()->json($stop->load('terminal'), 201);
    }

    public function update(Request $request, TransportRoute $route, RouteStop $stop): JsonResponse
    {
        $this->authorizeRoute($request, $route);
        abort_unless($stop->route_id === $route->id, 404);
        $stop->update($request->validate($this->rules($route, $stop, true)));

        return response()->json($stop->refresh()->load('terminal'));
    }

    public function destroy(Request $request, TransportRoute $route, RouteStop $stop): JsonResponse
    {
        $this->authorizeRoute($request, $route);
        abort_unless($stop->route_id === $route->id, 404);
        $stop->delete();

        return response()->json(status: 204);
    }

    public function profitability(Request $request, TransportRoute $route): JsonResponse
    {
        $this->authorizeRoute($request, $route);
        $tripIds = $route->trips()->pluck('id');
        $revenue = (float) Booking::whereIn('trip_id', $tripIds)->whereIn('status', ['confirmed', 'completed', 'partially_cancelled'])->sum('total');
        $expenses = (float) (new PlatformResource)->useModule('trip_expenses')->newQuery()->where('company_id', $route->company_id)->where('status', 'recorded')
            ->get()->filter(fn (PlatformResource $expense): bool => $tripIds->contains((int) data_get($expense->data, 'trip_id')))->sum('amount');

        return response()->json(['trips_operated' => $tripIds->count(), 'revenue' => round($revenue, 2), 'expenses' => round($expenses, 2), 'profit' => round($revenue - $expenses, 2)]);
    }

    /**
     * Route-specific commission tiers: whichever tier a booking's charge on this route falls into
     * (see TransportRoute::commissionRate()) overrides the operator's flat commission_rate for that
     * booking's platform fee, calculated in PricingService::quote() at booking time.
     */
    public function updateCommission(Request $request, TransportRoute $route): JsonResponse
    {
        $this->authorizeCommission($request, $route);
        $validated = $request->validate([
            'commission_tiers' => ['nullable', 'array'],
            'commission_tiers.*.min_amount' => ['required', 'numeric', 'min:0'],
            'commission_tiers.*.max_amount' => ['nullable', 'numeric', 'gt:commission_tiers.*.min_amount'],
            'commission_tiers.*.rate_percent' => ['required', 'numeric', 'between:0,100'],
        ]);
        $route->update(['commission_tiers' => $validated['commission_tiers'] ?? null]);

        return response()->json($route->refresh());
    }

    private function authorizeRoute(Request $request, TransportRoute $route): void
    {
        abort_unless($request->user()->can('routes.manage') && $request->user()->company_id === $route->company_id, 404);
    }

    private function authorizeCommission(Request $request, TransportRoute $route): void
    {
        abort_unless(($request->user()->can('finance.manage') || $request->user()->can('companies.manage')) && $request->user()->company_id === $route->company_id, 404);
    }

    /** @return array<string, mixed> */
    private function rules(TransportRoute $route, ?RouteStop $stop = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'terminal_id' => [$required, Rule::exists('terminals', 'id')],
            'sequence' => [$required, 'integer', 'min:1', Rule::unique('route_stops', 'sequence')->where('route_id', $route->id)->ignore($stop)],
            'arrival_offset_minutes' => ['nullable', 'integer', 'min:0'],
            'departure_offset_minutes' => ['nullable', 'integer', 'min:0', 'gte:arrival_offset_minutes'],
            'stop_duration_minutes' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
