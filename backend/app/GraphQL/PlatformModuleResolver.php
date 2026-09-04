<?php

namespace App\GraphQL;

use App\Models\PlatformResource;
use GraphQL\Error\Error;
use Illuminate\Support\Collection;

class PlatformModuleResolver
{
    /** @param array{module:string, status?:string} $arguments */
    public function __invoke(mixed $root, array $arguments): Collection
    {
        if (! config("platform.modules.{$arguments['module']}", false)) {
            throw new Error('Unknown platform module.');
        }
        $user = auth('sanctum')->user();
        if (! $user) {
            throw new Error('Unauthenticated.');
        }
        if ($user->company && in_array($user->company->status, ['suspended', 'rejected', 'closed'], true)) {
            throw new Error('Company access is suspended.');
        }
        if (! in_array($user->role, config('platform.platform_roles'), true)) {
            $permission = $this->requiredPermission($arguments['module'], (bool) $user->company_id);
            if ($permission && ! $user->can($permission)) {
                throw new Error('Forbidden.');
            }
        }
        $query = (new PlatformResource)->useModule($arguments['module'])->newQuery();
        if (! in_array($user->role, config('platform.platform_roles'), true)) {
            $user->company_id ? $query->where('company_id', $user->company_id) : $query->where('user_id', $user->id);
        }

        return $query->when($arguments['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))->latest()->limit(100)->get();
    }

    private function requiredPermission(string $module, bool $hasCompany): ?string
    {
        $personal = ['profiles', 'saved_passengers', 'saved_routes', 'saved_payment_methods', 'notification_preferences', 'login_devices', 'account_requests', 'recent_searches', 'emergency_contacts', 'travel_documents', 'notifications', 'reviews', 'support_cases', 'support_messages', 'wallets', 'wallet_transactions', 'loyalty_accounts', 'loyalty_transactions', 'referrals', 'waitlists', 'tracking_links', 'lost_properties', 'terms_acceptances', 'passenger_preferences', 'trip_comparisons', 'booking_claims', 'receipts'];
        if (! $hasCompany) {
            return in_array($module, $personal, true) ? null : 'platform.manage';
        }

        return match (true) {
            str_contains($module, 'agent') => 'agents.manage',
            str_contains($module, 'parcel') || $module === 'collection_proofs' => 'parcels.manage',
            str_contains($module, 'support') => 'support.manage',
            str_contains($module, 'security') || str_contains($module, 'api_client') || str_contains($module, 'webhook') => 'security.manage',
            str_contains($module, 'report') || str_contains($module, 'analytics') || in_array($module, ['api_usage_records', 'consistency_checks'], true) => 'reports.view',
            str_contains($module, 'wallet') || str_contains($module, 'settlement') || str_contains($module, 'payment') || in_array($module, ['refunds', 'commissions', 'reconciliations', 'financial_ledger_entries', 'receipts', 'integration_logs'], true) => 'finance.manage',
            str_contains($module, 'bus_') || in_array($module, ['drivers', 'conductors', 'seat_layouts', 'maintenance_records', 'incidents', 'pre_trip_checklists'], true) => 'fleet.manage',
            str_contains($module, 'boarding') || str_contains($module, 'offline_sync') => 'boarding.manage',
            in_array($module, ['route_stops', 'schedules', 'trip_stops', 'trip_staff', 'fares', 'fare_rules', 'trip_status_updates', 'vehicle_locations', 'gps_locations'], true) => 'trips.manage',
            in_array($module, ['company_documents', 'branches', 'employees', 'staff_assignments', 'operator_policies'], true) => 'companies.manage',
            default => 'platform.manage',
        };
    }
}
