<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantSecurityFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_login_succeeds_and_records_a_security_audit(): void
    {
        $user = User::factory()->create(['phone' => '+263771234567', 'password' => 'StrongPass123']);

        $this->postJson('/api/v1/auth/login', ['login' => '+263771234567', 'password' => 'StrongPass123', 'device_name' => 'Android'])
            ->assertOk()
            ->assertJsonStructure(['token']);
        $this->assertDatabaseHas('security_audits', ['user_id' => $user->id, 'event' => 'login_succeeded']);
    }

    public function test_fifth_invalid_login_temporarily_locks_the_account(): void
    {
        $user = User::factory()->create(['password' => 'StrongPass123']);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'WrongPassword', 'device_name' => 'Browser'])
                ->assertUnprocessable();
        }

        $this->assertTrue($user->refresh()->locked_until->isFuture());
        $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'StrongPass123', 'device_name' => 'Browser'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('login');
    }

    public function test_company_owner_invites_staff_and_invitation_creates_scoped_account(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Mufambi', 'slug' => 'mufambi', 'status' => 'active']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Harare', 'code' => 'HRE']);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_owner']);
        $owner->assignRole('company_owner');
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/staff-invitations', ['email' => 'driver@example.com', 'branch_id' => $branch->id, 'role' => 'driver'])
            ->assertCreated();
        $token = $response->json('invitation_token');
        auth()->forgetGuards();

        $this->postJson('/api/v1/staff-invitations/accept', [
            'token' => $token,
            'name' => 'Driver One',
            'phone' => '+263772345678',
            'password' => 'StrongPass123',
            'password_confirmation' => 'StrongPass123',
        ])->assertCreated()->assertJsonPath('data.company_id', $company->id)->assertJsonPath('data.branch_id', $branch->id);
        $this->assertDatabaseHas('model_has_roles', ['model_id' => User::where('email', 'driver@example.com')->value('id')]);
    }

    public function test_returns_404_when_assigning_role_to_another_company_user(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Mufambi', 'slug' => 'mufambi', 'status' => 'active']);
        $otherCompany = Company::create(['name' => 'Competitor', 'slug' => 'competitor', 'status' => 'active']);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_owner']);
        $owner->assignRole('company_owner');
        $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);
        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/staff/{$otherUser->id}/role", ['role' => 'driver'])->assertNotFound();
        $this->assertDatabaseMissing('role_assignment_audits', ['user_id' => $otherUser->id]);
    }

    public function test_suspended_company_staff_receive_403_for_protected_operations(): void
    {
        $company = Company::create(['name' => 'Suspended', 'slug' => 'suspended', 'status' => 'suspended']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/staff')->assertForbidden();
    }
}
