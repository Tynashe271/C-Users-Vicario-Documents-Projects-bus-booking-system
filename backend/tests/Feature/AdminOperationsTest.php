<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_administrator_can_monitor_platform_records_without_sensitive_user_fields(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['role' => 'super_administrator']);
        $admin->assignRole('super_administrator');
        User::factory()->create(['name' => 'Visible Passenger', 'role' => 'passenger']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/operations/passengers?search=Visible')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Visible Passenger')
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.two_factor_secret');
    }

    public function test_super_administrator_can_revise_a_companys_commission_rate_and_see_it_on_the_dashboard(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['role' => 'super_administrator']);
        $admin->assignRole('super_administrator');
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-commission', 'settings' => ['commission_rate' => 5]]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/companies/{$company->id}/commission", ['commission_rate' => 8, 'agent_commission_rate' => 3])
            ->assertOk()->assertJsonPath('settings.commission_rate', 8)->assertJsonPath('settings.agent_commission_rate', 3);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'name' => 'Company commission rate changed']);

        $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('security_alerts', 0);
    }

    public function test_company_administrator_cannot_monitor_other_companies(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Operator', 'slug' => 'operator', 'status' => 'active']);
        $admin = User::factory()->create(['company_id' => $company->id, 'role' => 'company_administrator']);
        $admin->assignRole('company_administrator');
        Bus::create(['company_id' => $company->id, 'registration_number' => 'OWN-10', 'model' => 'Scania', 'seat_capacity' => 50]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/operations/buses')->assertForbidden();
    }

    public function test_security_administrator_can_create_staff_and_suspend_passenger_with_audit_logs(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $security = User::factory()->create(['role' => 'security_administrator']);
        $security->assignRole('security_administrator');
        $passenger = User::factory()->create(['role' => 'passenger']);
        Sanctum::actingAs($security);

        $this->postJson('/api/v1/admin/platform-staff', [
            'name' => 'Approval Officer',
            'email' => 'approvals@example.test',
            'password' => 'VerySecurePassword123!',
            'role' => 'operator_approval_officer',
        ])->assertCreated()->assertJsonMissingPath('password');
        $this->assertDatabaseHas('users', ['email' => 'approvals@example.test', 'company_id' => null, 'role' => 'operator_approval_officer']);

        $this->patchJson("/api/v1/admin/users/{$passenger->id}/status", ['status' => 'suspended', 'reason' => 'Confirmed account abuse'])
            ->assertOk()
            ->assertJsonPath('status', 'suspended');
        $this->assertDatabaseHas('users', ['id' => $passenger->id, 'status' => 'suspended']);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_unknown_monitoring_resource_returns_not_found(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['role' => 'super_administrator']);
        $admin->assignRole('super_administrator');
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/operations/secrets')->assertNotFound();
    }
}
