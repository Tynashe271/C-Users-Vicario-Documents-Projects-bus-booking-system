<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FleetManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_fleet_manager_can_manage_documents_and_the_maintenance_lifecycle(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star']);
        $manager = User::factory()->create(['company_id' => $company->id, 'role' => 'fleet_manager']);
        $manager->assignRole('fleet_manager');
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'FLEET123', 'model' => 'Scania Touring', 'seat_capacity' => 52, 'mileage_km' => 100000]);

        $this->actingAs($manager)->patchJson("/api/v1/fleet/buses/{$bus->id}", ['manufacturing_year' => 2024, 'ownership_status' => 'owned', 'gps_device_identifier' => 'GPS-100', 'amenities' => ['wifi', 'toilet']])
            ->assertOk()->assertJsonPath('manufacturing_year', 2024)->assertJsonPath('gps_device_identifier', 'GPS-100');

        $this->actingAs($manager)->post("/api/v1/fleet/buses/{$bus->id}/documents", ['document_type' => 'insurance', 'code' => 'INS-100', 'issued_on' => today()->subYear()->toDateString(), 'expires_on' => today()->addDays(20)->toDateString(), 'document' => UploadedFile::fake()->create('insurance.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('document_type', 'insurance');

        $maintenanceId = $this->actingAs($manager)->postJson("/api/v1/fleet/buses/{$bus->id}/maintenance", ['maintenance_type' => 'service', 'scheduled_at' => now()->subHour()->toIso8601String(), 'odometer_km' => 100000, 'amount' => 250, 'currency' => 'USD'])
            ->assertCreated()->json('id');
        $this->actingAs($manager)->postJson("/api/v1/fleet/maintenance/{$maintenanceId}/start")->assertOk()->assertJsonPath('status', 'in_progress');
        $this->assertDatabaseHas('buses', ['id' => $bus->id, 'status' => 'under_maintenance']);
        $this->actingAs($manager)->postJson("/api/v1/fleet/maintenance/{$maintenanceId}/complete", ['odometer_km' => 100250, 'notes' => 'Oil and filters replaced'])->assertOk()->assertJsonPath('status', 'completed');
        $this->assertDatabaseHas('buses', ['id' => $bus->id, 'status' => 'available', 'mileage_km' => 100250]);

        $this->actingAs($manager)->getJson('/api/v1/fleet/dashboard')->assertOk()->assertJsonPath('fleet_total', 1)->assertJsonCount(1, 'expiring_documents');
    }

    public function test_fleet_manager_cannot_access_another_company_bus(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star']);
        $otherCompany = Company::create(['name' => 'Other', 'slug' => 'other']);
        $manager = User::factory()->create(['company_id' => $company->id, 'role' => 'fleet_manager']);
        $manager->assignRole('fleet_manager');
        $otherBus = Bus::create(['company_id' => $otherCompany->id, 'registration_number' => 'OTHER123', 'model' => 'Volvo', 'seat_capacity' => 40]);

        $this->actingAs($manager)->patchJson("/api/v1/fleet/buses/{$otherBus->id}", ['status' => 'suspended'])->assertNotFound();
    }
}
