<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManagementDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_administrator_dashboard_returns_platform_wide_totals(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['role' => 'super_administrator']);
        $admin->assignRole('super_administrator');
        Company::create(['name' => 'Active Operator', 'slug' => 'active-operator', 'status' => 'active']);
        Company::create(['name' => 'Pending Operator', 'slug' => 'pending-operator', 'status' => 'under_review']);
        Company::create(['name' => 'Suspended Operator', 'slug' => 'suspended-operator', 'status' => 'suspended']);
        User::factory()->create(['role' => 'passenger']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('companies.total', 3)
            ->assertJsonPath('companies.approved', 1)
            ->assertJsonPath('companies.pending', 1)
            ->assertJsonPath('companies.suspended', 1)
            ->assertJsonPath('passengers', 1);
    }

    public function test_company_dashboard_contains_only_authenticated_company_data(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Own Operator', 'slug' => 'own-operator', 'status' => 'active']);
        $otherCompany = Company::create(['name' => 'Other Operator', 'slug' => 'other-operator', 'status' => 'active']);
        $admin = User::factory()->create(['company_id' => $company->id, 'role' => 'company_administrator']);
        $admin->assignRole('company_administrator');
        Bus::create(['company_id' => $company->id, 'registration_number' => 'OWN-001', 'model' => 'Scania', 'seat_capacity' => 50, 'status' => 'available']);
        Bus::create(['company_id' => $otherCompany->id, 'registration_number' => 'OTHER-001', 'model' => 'Volvo', 'seat_capacity' => 60, 'status' => 'available']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/company/dashboard')
            ->assertOk()
            ->assertJsonPath('buses.total', 1)
            ->assertJsonPath('buses.available', 1);
    }

    public function test_company_administrator_receives_403_for_platform_dashboard(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Operator', 'slug' => 'operator', 'status' => 'active']);
        $admin = User::factory()->create(['company_id' => $company->id, 'role' => 'company_administrator']);
        $admin->assignRole('company_administrator');
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_super_administrator_suspends_and_reactivates_company_with_audit_history(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['role' => 'super_administrator']);
        $admin->assignRole('super_administrator');
        $company = Company::create(['name' => 'Operator', 'slug' => 'operator', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/companies/{$company->id}/status", ['status' => 'suspended', 'reason' => 'Expired operator licence'])
            ->assertOk()
            ->assertJsonPath('status', 'suspended');
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'name' => 'Company status changed from active to suspended', 'status' => 'recorded']);

        $this->patchJson("/api/v1/admin/companies/{$company->id}/status", ['status' => 'active', 'reason' => 'Licence renewed'])
            ->assertOk()
            ->assertJsonPath('status', 'active');
        $this->assertDatabaseCount('audit_logs', 2);
    }
}
