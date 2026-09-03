<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PlatformResource;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GlobalUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_administrator_suspends_a_passenger_revoking_their_sessions_and_records_audit_history(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $security = User::factory()->create(['role' => 'security_administrator']);
        $security->assignRole('security_administrator');
        $passenger = User::factory()->create(['role' => 'passenger']);
        $token = $passenger->createToken('device')->plainTextToken;
        $this->assertSame(1, $passenger->tokens()->count());

        Sanctum::actingAs($security);
        $this->patchJson("/api/v1/admin/global-users/{$passenger->id}/status", ['status' => 'suspended', 'reason' => 'Reported for abusive behaviour'])
            ->assertOk()->assertJsonPath('status', 'suspended');

        $this->assertDatabaseHas('users', ['id' => $passenger->id, 'status' => 'suspended']);
        $this->assertSame(0, $passenger->tokens()->count());

        $history = $this->getJson("/api/v1/admin/global-users/{$passenger->id}/audit-history")->assertOk()->json();
        $this->assertCount(1, $history);
        $this->assertSame('Reported for abusive behaviour', $history[0]['data']['reason']);

        // Reactivating restores access; a fresh token can be revoked independently too.
        $this->patchJson("/api/v1/admin/global-users/{$passenger->id}/status", ['status' => 'active', 'reason' => 'Appeal accepted'])->assertOk()->assertJsonPath('status', 'active');
        $passenger->createToken('device-2');
        $this->postJson("/api/v1/admin/global-users/{$passenger->id}/session-revocation")->assertOk()->assertJsonPath('revoked_sessions', 1);
    }

    public function test_a_security_administrator_cannot_touch_their_own_or_a_super_administrators_account(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $security = User::factory()->create(['role' => 'security_administrator']);
        $security->assignRole('security_administrator');
        $superAdmin = User::factory()->create(['role' => 'super_administrator']);
        Sanctum::actingAs($security);

        $this->patchJson("/api/v1/admin/global-users/{$security->id}/status", ['status' => 'suspended', 'reason' => 'x'])->assertUnprocessable();
        $this->patchJson("/api/v1/admin/global-users/{$superAdmin->id}/status", ['status' => 'suspended', 'reason' => 'x'])->assertUnprocessable();
    }

    public function test_admin_operations_search_covers_agents_and_drivers(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $security = User::factory()->create(['role' => 'security_administrator']);
        $security->assignRole('security_administrator');
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-ops']);
        $agentUser = User::factory()->create(['company_id' => $company->id, 'name' => 'Ada Agent']);
        (new PlatformResource)->useModule('agents')->newQuery()->create(['company_id' => $company->id, 'user_id' => $agentUser->id, 'code' => 'AGT-1', 'name' => 'Ada Agent', 'status' => 'approved']);
        Employee::create(['company_id' => $company->id, 'employee_number' => 'DRV-1', 'code' => 'DRV-1', 'name' => 'Danny Driver', 'staff_type' => 'driver', 'status' => 'active']);

        Sanctum::actingAs($security);
        $this->getJson('/api/v1/admin/operations/agents?search=Ada')->assertOk()->assertJsonPath('data.0.name', 'Ada Agent');
        $this->getJson('/api/v1/admin/operations/drivers?search=Danny')->assertOk()->assertJsonPath('data.0.name', 'Danny Driver');
    }
}
