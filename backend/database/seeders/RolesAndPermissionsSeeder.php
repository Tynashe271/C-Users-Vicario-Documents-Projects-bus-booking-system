<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = ['platform.manage', 'companies.manage', 'fleet.manage', 'routes.manage', 'trips.manage', 'bookings.manage', 'boarding.manage', 'finance.manage', 'agents.manage', 'parcels.manage', 'support.manage', 'marketing.manage', 'reports.view', 'security.manage', 'profile.manage'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = array_merge(['passenger', 'guest_passenger', 'corporate_passenger', 'student_passenger', 'frequent_traveller', 'operator_applicant'], config('platform.company_roles'), config('platform.platform_roles'));
        foreach ($roles as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($roleName === 'super_administrator' ? $permissions : $this->permissionsFor($roleName));
        }
    }

    /** @return list<string> */
    private function permissionsFor(string $role): array
    {
        return match ($role) {
            'company_owner', 'company_administrator' => ['companies.manage', 'fleet.manage', 'routes.manage', 'trips.manage', 'bookings.manage', 'boarding.manage', 'finance.manage', 'agents.manage', 'parcels.manage', 'support.manage', 'marketing.manage', 'reports.view'],
            'operations_manager' => ['fleet.manage', 'routes.manage', 'trips.manage', 'bookings.manage', 'boarding.manage', 'reports.view'],
            'fleet_manager', 'maintenance_officer' => ['fleet.manage', 'reports.view'],
            'finance_manager', 'platform_finance_officer' => ['finance.manage', 'reports.view'],
            'booking_clerk', 'branch_manager' => ['bookings.manage', 'boarding.manage', 'reports.view'],
            'driver', 'conductor', 'terminal_officer' => ['boarding.manage'],
            'customer_support_agent', 'customer_support_officer' => ['support.manage', 'bookings.manage'],
            'marketing_manager' => ['marketing.manage', 'reports.view'],
            'security_administrator', 'system_auditor' => ['security.manage', 'reports.view'],
            'operator_approval_officer' => ['companies.manage'],
            default => ['profile.manage'],
        };
    }
}
