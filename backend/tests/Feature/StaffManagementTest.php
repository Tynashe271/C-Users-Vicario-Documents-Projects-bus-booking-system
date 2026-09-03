<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_creates_trained_driver_and_assigns_them_without_schedule_conflicts(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [$company, $trip, $overlappingTrip] = $this->tripFixture();
        $manager = User::factory()->create(['company_id' => $company->id, 'role' => 'operations_manager']);
        $manager->assignRole('operations_manager');

        $employeeId = $this->actingAs($manager)->postJson('/api/v1/staff', ['employee_number' => 'DRV-001', 'name' => 'Tawanda Driver', 'staff_type' => 'driver', 'hired_on' => today()->subYear()->toDateString(), 'driver_licence_number' => 'DL-SECRET-1', 'driver_licence_class' => '2', 'driver_licence_expires_on' => today()->addYear()->toDateString(), 'emergency_contact' => ['name' => 'Relative', 'phone' => '0772000000']])->assertCreated()->json('id');

        $this->actingAs($manager)->postJson("/api/v1/staff/{$employeeId}/training", ['course_name' => 'Passenger safety', 'provider' => 'Transport Board', 'completed_on' => today()->subMonth()->toDateString(), 'expires_on' => today()->addDays(30)->toDateString()])->assertCreated();
        $this->actingAs($manager)->postJson("/api/v1/staff/{$employeeId}/assignments", ['trip_id' => $trip->id, 'duty_role' => 'driver'])->assertCreated()->assertJsonPath('status', 'assigned');
        $this->actingAs($manager)->postJson("/api/v1/staff/{$employeeId}/assignments", ['trip_id' => $overlappingTrip->id, 'duty_role' => 'driver'])->assertConflict();

        $this->actingAs($manager)->getJson('/api/v1/staff-alerts')->assertOk()->assertJsonCount(1, 'training');
        $this->actingAs($manager)->getJson('/api/v1/staff?staff_type=driver')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Tawanda Driver');
    }

    public function test_leave_cannot_be_approved_while_employee_has_an_assignment_and_tenants_are_isolated(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [$company, $trip] = $this->tripFixture();
        $manager = User::factory()->create(['company_id' => $company->id, 'role' => 'operations_manager']);
        $manager->assignRole('operations_manager');
        $employeeId = $this->actingAs($manager)->postJson('/api/v1/staff', ['employee_number' => 'CON-001', 'name' => 'Conductor', 'staff_type' => 'conductor'])->assertCreated()->json('id');
        $this->actingAs($manager)->postJson("/api/v1/staff/{$employeeId}/assignments", ['trip_id' => $trip->id, 'duty_role' => 'conductor'])->assertCreated();
        $leaveId = $this->actingAs($manager)->postJson("/api/v1/staff/{$employeeId}/leave", ['leave_type' => 'annual', 'starts_on' => $trip->departs_at->toDateString(), 'ends_on' => $trip->arrives_at->toDateString()])->assertCreated()->json('id');
        $this->actingAs($manager)->postJson("/api/v1/staff/leave/{$leaveId}/approve")->assertConflict();

        $other = Company::create(['name' => 'Other', 'slug' => 'other']);
        $otherManager = User::factory()->create(['company_id' => $other->id, 'role' => 'operations_manager']);
        $otherManager->assignRole('operations_manager');
        $this->actingAs($otherManager)->patchJson("/api/v1/staff/{$employeeId}", ['availability_status' => 'suspended'])->assertNotFound();
    }

    /** @return array{Company, Trip, Trip} */
    private function tripFixture(): array
    {
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star']);
        $origin = Terminal::create(['name' => 'Roadport', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Intercity', 'city' => 'Bulawayo', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare to Bulawayo', 'duration_minutes' => 360]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'STAFF123', 'model' => 'Scania', 'seat_capacity' => 50]);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDay(), 'arrives_at' => now()->addDay()->addHours(6), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'available']);
        $overlappingTrip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDay()->addHour(), 'arrives_at' => now()->addDay()->addHours(7), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'available']);

        return [$company, $trip, $overlappingTrip];
    }
}
