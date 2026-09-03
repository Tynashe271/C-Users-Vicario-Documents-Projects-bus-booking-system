<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Bus;
use App\Models\Payment;
use App\Models\PlatformResource;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyDashboardController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->company_id && $request->user()->can('reports.view'), 403);
        $companyId = $request->user()->company_id;
        $todayBookings = Booking::where('company_id', $companyId)->whereDate('created_at', today());
        $confirmedSeats = BookingPassenger::whereHas('booking', fn ($query) => $query->where('company_id', $companyId))->where('status', 'confirmed')->count();
        $seatCapacity = Bus::where('company_id', $companyId)->sum('seat_capacity');
        $auditQuery = (new PlatformResource)->useModule('audit_logs')->newQuery()->where('company_id', $companyId);

        return response()->json([
            'branches' => $request->user()->company->branches()->count(),
            'staff' => User::where('company_id', $companyId)->count(),
            'buses' => [
                'total' => Bus::where('company_id', $companyId)->count(),
                'available' => Bus::where('company_id', $companyId)->where('status', 'available')->count(),
                'under_maintenance' => Bus::where('company_id', $companyId)->where('status', 'maintenance')->count(),
            ],
            'scheduled_trips' => Trip::where('company_id', $companyId)->whereIn('status', ['scheduled', 'published', 'available', 'almost_full'])->count(),
            'active_trips' => Trip::where('company_id', $companyId)->whereIn('status', ['scheduled', 'published', 'available', 'almost_full', 'boarding', 'departed', 'delayed'])->count(),
            'today_bookings' => (clone $todayBookings)->count(),
            'today_revenue' => (float) Payment::whereHas('booking', fn ($query) => $query->where('company_id', $companyId))->where('status', 'paid')->whereDate('paid_at', today())->sum('amount'),
            'seat_occupancy_percent' => $seatCapacity > 0 ? round(($confirmedSeats / $seatCapacity) * 100, 2) : 0,
            'delayed_trips' => Trip::where('company_id', $companyId)->where('status', 'delayed')->count(),
            'pending_refunds' => (new PlatformResource)->useModule('refunds')->newQuery()->where('company_id', $companyId)->whereIn('status', ['pending', 'requested', 'under_review', 'approved'])->count(),
            'next_settlement' => Settlement::where('company_id', $companyId)->whereIn('status', ['draft', 'pending_approval', 'approved'])->orderBy('period_end')->first(),
            'wallet_balance' => (float) Wallet::where('company_id', $companyId)->where('wallet_type', 'operator')->sum('available_balance'),
            'performance' => [
                'completed_trips' => Trip::where('company_id', $companyId)->where('status', 'completed')->count(),
                'completed_bookings' => Booking::where('company_id', $companyId)->where('status', 'completed')->count(),
            ],
            'recent_activities' => $auditQuery->latest('created_at')->latest('id')->limit(10)->get(),
        ]);
    }
}
