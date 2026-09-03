<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PlatformResource;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformModulesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic feature test example.
     */
    public function test_company_users_cannot_read_another_company_records(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $first = Company::create(['name' => 'First', 'slug' => 'first']);
        $second = Company::create(['name' => 'Second', 'slug' => 'second']);
        $user = User::factory()->create(['company_id' => $first->id, 'role' => 'company_administrator']);
        $user->assignRole('company_administrator');
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/modules/branches/records', ['name' => 'Harare'])->assertCreated();
        $other = (new PlatformResource)->useModule('branches');
        $other->fill(['company_id' => $second->id, 'name' => 'Bulawayo', 'user_id' => $user->id])->save();

        $this->getJson('/api/v1/modules/branches/records')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Harare');
    }

    public function test_graphql_returns_forbidden_for_passenger_financial_module_access(): void
    {
        $user = User::factory()->create(['role' => 'passenger']);
        Sanctum::actingAs($user);

        $this->postJson('/graphql', ['query' => '{ moduleRecords(module: "financial_ledger_entries") { id } }'])
            ->assertOk()
            ->assertJsonPath('errors.0.message', 'Forbidden.');
    }

    public function test_personal_extended_module_is_tenant_scoped(): void
    {
        $user = User::factory()->create(['role' => 'passenger']);
        $otherUser = User::factory()->create(['role' => 'passenger']);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/modules/trip_comparisons/records', ['name' => 'Harare options'])->assertCreated();
        $other = (new PlatformResource)->useModule('trip_comparisons');
        $other->fill(['user_id' => $otherUser->id, 'name' => 'Private comparison'])->save();

        $this->getJson('/api/v1/modules/trip_comparisons/records')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Harare options');
    }
}
