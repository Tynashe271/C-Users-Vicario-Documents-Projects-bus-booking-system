<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FleetAndStaffOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_fleet_manager_registers_tracks_transfers_and_replaces_a_bus(): void
    {
        [$company, $manager] = $this->managerFixture();
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Harare', 'code' => 'HRE', 'status' => 'active']);

        $busId = $this->actingAs($manager)->postJson('/api/v1/fleet/buses', ['registration_number' => 'ABC-1234', 'manufacturer' => 'Scania', 'model' => 'Touring', 'class' => 'executive', 'manufacturing_year' => 2025, 'seat_capacity' => 48, 'ownership_status' => 'owned', 'status' => 'available', 'amenities' => ['wifi', 'toilet']])->assertCreated()->assertJsonPath('manufacturer', 'Scania')->json('id');
        $replacement = Bus::create(['company_id' => $company->id, 'registration_number' => 'XYZ-9876', 'model' => 'Volvo 9700', 'seat_capacity' => 50]);

        $this->actingAs($manager)->postJson("/api/v1/fleet/buses/{$busId}/records", ['type' => 'fuel', 'occurred_at' => now()->toIso8601String(), 'odometer_km' => 12500, 'quantity' => 180.5, 'amount' => 260, 'currency' => 'USD'])->assertCreated()->assertJsonPath('type', 'fuel');
        $this->actingAs($manager)->postJson("/api/v1/fleet/buses/{$busId}/transfer", ['branch_id' => $branch->id])->assertOk()->assertJsonPath('current_branch_id', $branch->id);
        $this->actingAs($manager)->postJson("/api/v1/fleet/buses/{$busId}/replacement", ['replacement_bus_id' => $replacement->id])->assertOk()->assertJsonPath('status', 'retired');

        $this->assertDatabaseHas('buses', ['id' => $busId, 'mileage_km' => 12500, 'current_branch_id' => $branch->id, 'replaced_by_bus_id' => $replacement->id]);
        $this->assertDatabaseHas('bus_operational_records', ['bus_id' => $busId, 'type' => 'replacement']);
    }

    public function test_staff_manager_records_documents_hours_performance_and_access(): void
    {
        Storage::fake('local');
        [$company, $manager] = $this->managerFixture();
        $employee = Employee::create(['company_id' => $company->id, 'employee_number' => 'CON-22', 'code' => 'CON-22', 'name' => 'Nyasha Moyo', 'staff_type' => 'conductor', 'status' => 'active']);

        $this->actingAs($manager)->patchJson("/api/v1/staff/{$employee->id}", ['phone' => '+263771000000', 'email' => 'nyasha@example.test', 'manifest_access' => true, 'ticket_scanning_access' => true])->assertOk()->assertJsonPath('manifest_access', true);
        $this->actingAs($manager)->post("/api/v1/staff/{$employee->id}/documents", ['document_type' => 'identity', 'reference' => 'ID-22', 'expires_on' => today()->addYear()->toDateString(), 'document' => UploadedFile::fake()->create('identity.pdf', 50, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($manager)->postJson("/api/v1/staff/{$employee->id}/working-hours", ['clocked_in_at' => now()->subHours(8)->toIso8601String(), 'clocked_out_at' => now()->toIso8601String(), 'break_minutes' => 60])->assertCreated()->assertJsonPath('status', 'completed');
        $this->actingAs($manager)->postJson("/api/v1/staff/{$employee->id}/reports", ['type' => 'performance', 'occurred_at' => now()->toIso8601String(), 'rating' => 4.5, 'status' => 'final', 'notes' => 'Strong passenger service'])->assertCreated()->assertJsonPath('rating', '4.50');

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'manifest_access' => true, 'ticket_scanning_access' => true, 'rating' => 4.5]);
        $this->assertDatabaseHas('employee_documents', ['employee_id' => $employee->id, 'document_type' => 'identity']);
    }

    public function test_maintenance_completion_records_downtime_and_condition_and_alerts_flag_upcoming_service(): void
    {
        [$company, $manager] = $this->managerFixture();
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'MNT-1', 'model' => 'Yutong', 'seat_capacity' => 40, 'mileage_km' => 90500]);

        $maintenanceId = $this->actingAs($manager)->postJson("/api/v1/fleet/buses/{$bus->id}/maintenance", ['maintenance_type' => 'service', 'scheduled_at' => now()->toIso8601String(), 'odometer_km' => 90500, 'next_service_on' => today()->addDays(10)->toDateString(), 'next_service_odometer_km' => 91200])->assertCreated()->json('id');
        $this->actingAs($manager)->postJson("/api/v1/fleet/maintenance/{$maintenanceId}/approve")->assertOk();
        $this->actingAs($manager)->postJson("/api/v1/fleet/maintenance/{$maintenanceId}/start")->assertOk();
        $this->actingAs($manager)->postJson("/api/v1/fleet/maintenance/{$maintenanceId}/complete", ['odometer_km' => 90600, 'condition_rating' => 'good'])->assertOk()->assertJsonPath('condition_rating', 'good');

        $this->assertDatabaseHas('maintenance_records', ['id' => $maintenanceId, 'status' => 'completed', 'condition_rating' => 'good']);
        $this->assertNotNull(MaintenanceRecord::find($maintenanceId)->downtime_minutes);

        $alerts = $this->actingAs($manager)->getJson('/api/v1/fleet/maintenance/alerts')->assertOk()->json();
        $this->assertNotEmpty($alerts['due_by_date']);
        $this->assertNotEmpty($alerts['due_by_odometer']);
    }

    public function test_fleet_manager_suspends_and_reactivates_a_driver(): void
    {
        [$company, $manager] = $this->managerFixture();
        $employee = Employee::create(['company_id' => $company->id, 'employee_number' => 'DRV-9', 'code' => 'DRV-9', 'name' => 'Tafara Chikwanha', 'staff_type' => 'driver', 'status' => 'active', 'availability_status' => 'available']);

        $this->actingAs($manager)->postJson("/api/v1/staff/{$employee->id}/suspension", ['reason' => 'Licence under review'])->assertOk()->assertJsonPath('status', 'suspended')->assertJsonPath('availability_status', 'suspended');
        $this->actingAs($manager)->postJson("/api/v1/staff/{$employee->id}/suspension")->assertStatus(409);
        $this->actingAs($manager)->postJson("/api/v1/staff/{$employee->id}/activation")->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('availability_status', 'available');

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'status' => 'active']);
    }

    public function test_company_cannot_write_operational_records_for_another_tenant(): void
    {
        [$company, $manager] = $this->managerFixture();
        $otherCompany = Company::create(['name' => 'Other Coaches', 'slug' => 'other-coaches']);
        $bus = Bus::create(['company_id' => $otherCompany->id, 'registration_number' => 'OTHER-1', 'model' => 'Volvo', 'seat_capacity' => 44]);

        $this->actingAs($manager)->postJson("/api/v1/fleet/buses/{$bus->id}/records", ['type' => 'incident', 'occurred_at' => now()->toIso8601String()])->assertNotFound();
        $this->assertDatabaseMissing('bus_operational_records', ['bus_id' => $bus->id]);
    }

    /** @return array{Company, User} */
    private function managerFixture(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-'.str()->random(6)]);
        $manager = User::factory()->create(['company_id' => $company->id, 'role' => 'fleet_manager']);
        $manager->assignRole('fleet_manager');

        return [$company, $manager];
    }
}
