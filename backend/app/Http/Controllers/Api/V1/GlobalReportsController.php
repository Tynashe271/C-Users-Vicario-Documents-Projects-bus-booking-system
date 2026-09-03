<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Parcel;
use App\Models\Payment;
use App\Models\PlatformResource;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class GlobalReportsController extends Controller
{
    public function platform(Request $request): JsonResponse
    {
        $this->authorizePlatform($request);
        [$from, $to] = $this->period($request);

        $bookings = Booking::whereBetween('created_at', [$from, $to]);
        $confirmed = (clone $bookings)->whereIn('status', ['confirmed', 'completed']);
        $totalBookings = (clone $bookings)->count();
        $cancelledBookings = (clone $bookings)->whereIn('status', ['cancelled', 'partially_cancelled'])->count();
        $commissions = Commission::whereBetween('created_at', [$from, $to]);
        $grossBookingValue = round((float) (clone $confirmed)->sum('total'), 2);

        $refundedAmount = round((float) (new PlatformResource)->useModule('refunds')->newQuery()->where('status', 'approved')->whereBetween('created_at', [$from, $to])->sum('amount'), 2);

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_bookings' => $totalBookings,
            'gross_booking_value' => $grossBookingValue,
            'platform_revenue' => round((float) (clone $commissions)->sum('platform_amount'), 2),
            'operator_revenue' => round((float) (clone $commissions)->sum('operator_amount'), 2),
            'agent_commission' => round((float) (clone $commissions)->sum('agent_amount'), 2),
            'revenue_by_company' => $this->revenueByCompany($from, $to),
            'revenue_by_route' => $this->revenueByRoute($from, $to),
            'revenue_by_period' => $this->revenueByPeriod($from, $to),
            'popular_routes' => $this->popularRoutes($from, $to),
            'popular_operators' => $this->popularOperators($from, $to),
            'passenger_growth' => $this->passengerGrowth($from, $to),
            'payment_method_performance' => $this->paymentMethodPerformance($from, $to),
            'refund_rate_percent' => $grossBookingValue > 0 ? round($refundedAmount / $grossBookingValue * 100, 2) : 0.0,
            'total_refunded' => $refundedAmount,
            'cancellation_rate_percent' => $totalBookings > 0 ? round($cancelledBookings / $totalBookings * 100, 2) : 0.0,
            'seat_occupancy_percent' => $this->seatOccupancy($from, $to),
            'agent_performance' => $this->agentPerformance($from, $to),
            'parcel_performance' => $this->parcelPerformance($from, $to),
            'settlements' => ['paid' => round((float) Settlement::whereBetween('created_at', [$from, $to])->where('status', 'paid')->sum('net_amount'), 2), 'pending' => round((float) Settlement::whereBetween('created_at', [$from, $to])->whereIn('status', ['draft', 'approved'])->sum('net_amount'), 2)],
            'tax_collected' => round((float) (clone $confirmed)->sum('taxes'), 2),
        ]);
    }

    public function exportRevenueByCompany(Request $request): JsonResponse
    {
        $this->authorizePlatform($request);
        [$from, $to] = $this->period($request);
        $rows = $this->revenueByCompany($from, $to);
        $csv = "company,bookings,revenue\n".$rows->map(fn (array $row): string => implode(',', [str_replace(',', ' ', $row['company']), $row['bookings'], $row['revenue']]))->implode("\n");

        return response()->json(['filename' => "revenue-by-company-{$from->toDateString()}-to-{$to->toDateString()}.csv", 'csv' => $csv]);
    }

    /** @return Collection<int, array{company: string, bookings: int, revenue: float}> */
    private function revenueByCompany(Carbon $from, Carbon $to)
    {
        return Booking::query()->whereBetween('bookings.created_at', [$from, $to])->whereIn('bookings.status', ['confirmed', 'completed'])
            ->join('companies', 'companies.id', '=', 'bookings.company_id')
            ->selectRaw('companies.name as company, count(*) as bookings, sum(bookings.total) as revenue')
            ->groupBy('companies.id', 'companies.name')->orderByDesc('revenue')->limit(10)->get()
            ->map(fn ($row) => ['company' => $row->company, 'bookings' => (int) $row->bookings, 'revenue' => round((float) $row->revenue, 2)]);
    }

    private function revenueByRoute(Carbon $from, Carbon $to)
    {
        return Booking::query()->whereBetween('bookings.created_at', [$from, $to])->whereIn('bookings.status', ['confirmed', 'completed'])
            ->join('trips', 'trips.id', '=', 'bookings.trip_id')->join('routes', 'routes.id', '=', 'trips.route_id')
            ->selectRaw('routes.name as route, count(*) as bookings, sum(bookings.total) as revenue')
            ->groupBy('routes.id', 'routes.name')->orderByDesc('revenue')->limit(10)->get()
            ->map(fn ($row) => ['route' => $row->route, 'bookings' => (int) $row->bookings, 'revenue' => round((float) $row->revenue, 2)]);
    }

    private function revenueByPeriod(Carbon $from, Carbon $to)
    {
        return Booking::query()->whereBetween('created_at', [$from, $to])->whereIn('status', ['confirmed', 'completed'])
            ->selectRaw('DATE(created_at) as date, count(*) as bookings, sum(total) as revenue')
            ->groupBy('date')->orderBy('date')->get()
            ->map(fn ($row) => ['date' => $row->date, 'bookings' => (int) $row->bookings, 'revenue' => round((float) $row->revenue, 2)]);
    }

    private function popularRoutes(Carbon $from, Carbon $to)
    {
        return Booking::query()->whereBetween('bookings.created_at', [$from, $to])->whereIn('bookings.status', ['confirmed', 'completed'])
            ->join('trips', 'trips.id', '=', 'bookings.trip_id')->join('routes', 'routes.id', '=', 'trips.route_id')
            ->selectRaw('routes.name as route, count(*) as bookings')->groupBy('routes.id', 'routes.name')->orderByDesc('bookings')->limit(10)->get();
    }

    private function popularOperators(Carbon $from, Carbon $to)
    {
        return Booking::query()->whereBetween('bookings.created_at', [$from, $to])->whereIn('bookings.status', ['confirmed', 'completed'])
            ->join('companies', 'companies.id', '=', 'bookings.company_id')
            ->selectRaw('companies.name as company, count(*) as bookings')->groupBy('companies.id', 'companies.name')->orderByDesc('bookings')->limit(10)->get();
    }

    private function passengerGrowth(Carbon $from, Carbon $to)
    {
        return User::query()->where('role', 'passenger')->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as date, count(*) as signups')->groupBy('date')->orderBy('date')->get();
    }

    private function paymentMethodPerformance(Carbon $from, Carbon $to)
    {
        return Payment::query()->whereBetween('created_at', [$from, $to])->selectRaw('provider, status, count(*) as total')->groupBy('provider', 'status')->get()
            ->groupBy('provider')->map(function ($rows) {
                $total = $rows->sum('total');
                $paid = (int) $rows->where('status', 'paid')->sum('total');

                return ['attempts' => (int) $total, 'paid' => $paid, 'success_rate_percent' => $total > 0 ? round($paid / $total * 100, 1) : 0.0];
            });
    }

    private function seatOccupancy(Carbon $from, Carbon $to): float
    {
        $trips = Trip::whereBetween('departs_at', [$from, $to])->whereIn('status', ['boarding', 'departed', 'arrived', 'completed'])->with('bus:id,seat_capacity')->get();
        $capacity = (int) $trips->sum(fn (Trip $trip) => $trip->bus?->seat_capacity ?? 0);
        if ($capacity === 0) {
            return 0.0;
        }
        $confirmed = Booking::whereIn('trip_id', $trips->pluck('id'))->whereIn('status', ['confirmed', 'completed'])->withCount(['passengers' => fn ($query) => $query->where('status', 'confirmed')])->get()->sum('passengers_count');

        return round($confirmed / $capacity * 100, 2);
    }

    private function agentPerformance(Carbon $from, Carbon $to)
    {
        return Booking::query()->whereBetween('created_at', [$from, $to])->where('source', 'agent')->whereIn('status', ['confirmed', 'completed'])
            ->whereNotNull('user_id')->with('user:id,name')
            ->selectRaw('user_id, count(*) as bookings, sum(total) as revenue')->groupBy('user_id')->orderByDesc('revenue')->limit(10)->get()
            ->map(fn ($row) => ['agent' => $row->user?->name, 'bookings' => (int) $row->bookings, 'revenue' => round((float) $row->revenue, 2)]);
    }

    private function parcelPerformance(Carbon $from, Carbon $to)
    {
        $parcels = Parcel::whereBetween('created_at', [$from, $to])->get();

        return ['total' => $parcels->count(), 'revenue' => round((float) $parcels->sum('amount'), 2), 'collected' => $parcels->where('status', 'collected')->count(), 'in_transit' => $parcels->whereIn('status', ['loaded', 'in_transit'])->count()];
    }

    /** @return array{Carbon, Carbon} */
    private function period(Request $request): array
    {
        $validated = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return [isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : now()->subDays(30)->startOfDay(), isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : now()->endOfDay()];
    }

    private function authorizePlatform(Request $request): void
    {
        abort_unless(in_array($request->user()->role, config('platform.platform_roles'), true) && $request->user()->can('reports.view'), 403);
    }
}
