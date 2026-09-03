<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Commission;
use App\Models\Company;
use App\Models\Payment;
use App\Models\PlatformResource;
use App\Models\Settlement;
use App\Models\SupportCase;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('platform.manage'), 403);
        $companiesByStatus = Company::query()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $tripsByStatus = Trip::query()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $auditQuery = (new PlatformResource)->useModule('audit_logs')->newQuery();

        return response()->json([
            'companies' => [
                'total' => Company::count(),
                'approved' => (int) ($companiesByStatus['approved'] ?? 0) + (int) ($companiesByStatus['active'] ?? 0),
                'pending' => collect(['application_draft', 'draft', 'submitted', 'under_review', 'information_requested', 'changes_requested'])->sum(fn (string $status): int => (int) ($companiesByStatus[$status] ?? 0)),
                'suspended' => (int) ($companiesByStatus['suspended'] ?? 0),
            ],
            'passengers' => User::where('role', 'passenger')->count(),
            'buses' => Bus::count(),
            'trips' => [
                'active' => collect(['scheduled', 'published', 'available', 'almost_full', 'boarding', 'departed', 'delayed'])->sum(fn (string $status): int => (int) ($tripsByStatus[$status] ?? 0)),
                'completed' => (int) ($tripsByStatus['completed'] ?? 0),
                'cancelled' => (int) ($tripsByStatus['cancelled'] ?? 0),
            ],
            'bookings' => Booking::count(),
            'ticket_revenue' => (float) Payment::where('status', 'paid')->sum('amount'),
            'platform_commission' => (float) Commission::sum('platform_amount'),
            'pending_refunds' => (new PlatformResource)->useModule('refunds')->newQuery()->whereIn('status', ['pending', 'requested', 'under_review', 'approved'])->count(),
            'pending_settlements' => Settlement::whereIn('status', ['draft', 'pending_approval', 'approved'])->count(),
            'open_support_cases' => SupportCase::whereNotIn('status', ['resolved', 'closed'])->count(),
            'security_alerts' => (new PlatformResource)->useModule('security_events')->newQuery()->whereIn('status', ['open', 'flagged'])->count(),
            'recent_activities' => $auditQuery->latest('created_at')->latest('id')->limit(10)->get(),
        ]);
    }
}
