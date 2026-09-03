<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PlatformResource;
use App\Services\PassengerJourneyNotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformResourceController extends Controller
{
    public function index(Request $request, string $module): AnonymousResourceCollection
    {
        $query = $this->tenantQuery($request, $module);
        $request->validate(['status' => ['nullable', 'string', 'max:50'], 'search' => ['nullable', 'string', 'max:100']]);
        $query->when($request->string('status')->isNotEmpty(), fn (Builder $builder) => $builder->where('status', $request->string('status')));
        $query->when($request->string('search')->isNotEmpty(), fn (Builder $builder) => $builder->where(fn (Builder $nested) => $nested->where('name', 'like', '%'.$request->string('search').'%')->orWhere('code', 'like', '%'.$request->string('search').'%')));

        return JsonResource::collection($query->latest()->paginate(min($request->integer('per_page', 20), 100)));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, string $module): JsonResource
    {
        $this->authorizeModule($request, $module);
        $validated = $this->validated($request);
        $user = $request->user();
        $resource = (new PlatformResource)->useModule($this->module($module));
        $resource->fill($validated);
        $isPlatformUser = $this->isPlatformUser($user->role);
        $resource->company_id = $isPlatformUser ? ($validated['company_id'] ?? null) : $user->company_id;
        $resource->user_id = $isPlatformUser ? ($validated['user_id'] ?? $user->id) : $user->id;
        $resource->save();

        return JsonResource::make($resource);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $module, int $id): JsonResource
    {
        return JsonResource::make($this->tenantQuery($request, $module)->findOrFail($id));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $module, int $id, PassengerJourneyNotificationService $notifications): JsonResource
    {
        $resource = $this->tenantQuery($request, $module)->findOrFail($id);
        $resource->update($this->validated($request, true));
        if ($module === 'refunds' && data_get($resource->data, 'booking_id')) {
            $booking = Booking::find(data_get($resource->data, 'booking_id'));
            if ($booking) {
                $notifications->refundStatus($booking, $resource->status, (float) $resource->amount);
            }
        }

        return JsonResource::make($resource->refresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $module, int $id): JsonResponse
    {
        $this->tenantQuery($request, $module)->findOrFail($id)->delete();

        return response()->json(status: 204);
    }

    private function tenantQuery(Request $request, string $module): Builder
    {
        $this->authorizeModule($request, $module);
        $query = (new PlatformResource)->useModule($this->module($module))->newQuery();
        $user = $request->user();
        if ($this->isPlatformUser($user->role)) {
            return $query;
        }
        if ($user->company_id) {
            return $query->where('company_id', $user->company_id);
        }

        return $query->where('user_id', $user->id);
    }

    private function module(string $module): string
    {
        abort_unless(config("platform.modules.$module", false), 404, 'Unknown platform module.');

        return $module;
    }

    private function isPlatformUser(string $role): bool
    {
        return in_array($role, config('platform.platform_roles'), true);
    }

    private function authorizeModule(Request $request, string $module): void
    {
        $this->module($module);
        $user = $request->user();
        $personal = ['profiles', 'saved_passengers', 'saved_routes', 'saved_payment_methods', 'notification_preferences', 'login_devices', 'account_requests', 'recent_searches', 'emergency_contacts', 'travel_documents', 'notifications', 'reviews', 'support_cases', 'support_messages', 'wallets', 'wallet_transactions', 'loyalty_accounts', 'loyalty_transactions', 'referrals', 'waitlists', 'tracking_links', 'lost_properties', 'terms_acceptances', 'passenger_preferences', 'trip_comparisons', 'booking_claims', 'receipts'];
        if (! $user->company_id && ! $this->isPlatformUser($user->role)) {
            abort_unless(in_array($module, $personal, true), 403);

            return;
        }
        $permission = match (true) {
            str_contains($module, 'agent') => 'agents.manage',
            str_contains($module, 'parcel') || $module === 'collection_proofs' => 'parcels.manage',
            str_contains($module, 'support') || in_array($module, ['lost_properties', 'faq_articles'], true) => 'support.manage',
            str_contains($module, 'campaign') || in_array($module, ['promotions', 'coupons', 'featured_listings', 'review_moderations'], true) => 'marketing.manage',
            str_contains($module, 'security') || str_contains($module, 'audit') || str_contains($module, 'api_client') => 'security.manage',
            str_contains($module, 'report') || str_contains($module, 'analytics') => 'reports.view',
            str_contains($module, 'wallet') || str_contains($module, 'settlement') || str_contains($module, 'payment') || in_array($module, ['refunds', 'commissions', 'reconciliations', 'chargebacks', 'fraud_alerts', 'corporate_invoices', 'cost_centres', 'gift_cards', 'vouchers', 'financial_ledger_entries', 'receipts'], true) => 'finance.manage',
            str_contains($module, 'bus_') || in_array($module, ['drivers', 'conductors', 'seat_layouts', 'maintenance_records', 'incidents', 'fuel_records', 'inspection_records', 'insurance_records', 'permit_records', 'working_hours', 'leave_records', 'training_records', 'performance_reports'], true) => 'fleet.manage',
            str_contains($module, 'boarding') || str_contains($module, 'luggage') || str_contains($module, 'offline_sync') || $module === 'pre_trip_checklists' => 'boarding.manage',
            in_array($module, ['route_stops', 'schedules', 'trip_stops', 'trip_staff', 'fares', 'fare_rules', 'trip_expenses', 'vehicle_locations', 'gps_locations', 'trip_status_updates'], true) => 'trips.manage',
            in_array($module, ['company_documents', 'branches', 'employees', 'staff_assignments', 'corporate_accounts', 'corporate_members', 'operator_policies'], true) => 'companies.manage',
            default => 'platform.manage',
        };
        abort_unless($user->can($permission), 403);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'nullable';

        return $request->validate([
            'company_id' => [$presence, 'integer', 'exists:companies,id'], 'user_id' => [$presence, 'integer', 'exists:users,id'],
            'code' => [$presence, 'string', 'max:100'], 'name' => [$presence, 'string', 'max:255'], 'status' => [$presence, 'string', 'max:50'],
            'amount' => [$presence, 'numeric'], 'currency' => [$presence, 'string', 'size:3'], 'data' => [$presence, 'array'],
            'starts_at' => [$presence, 'date'], 'ends_at' => [$presence, 'date', 'after_or_equal:starts_at'],
        ]);
    }
}
