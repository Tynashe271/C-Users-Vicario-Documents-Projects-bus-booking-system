<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoreManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_administrator_can_manage_only_own_fleet(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Operator', 'slug' => 'operator']);
        $other = Company::create(['name' => 'Other', 'slug' => 'other']);
        $user = User::factory()->create(['company_id' => $company->id, 'role' => 'company_administrator']);
        $user->assignRole('company_administrator');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/management/buses/records', ['registration_number' => 'ABC123', 'model' => 'Scania', 'seat_capacity' => 50])->assertCreated()->assertJsonPath('data.company_id', $company->id);
        Bus::create(['company_id' => $other->id, 'registration_number' => 'XYZ789', 'model' => 'Volvo', 'seat_capacity' => 45]);
        $this->getJson('/api/v1/management/buses/records')->assertOk()->assertJsonCount(1, 'data');
    }
}
