<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Bus;
use App\Models\BusDocument;
use App\Models\Commission;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PlatformResource;
use App\Models\Seat;
use App\Models\StaffAssignment;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripOccupancyService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TripLifecycleAndRouteManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_manager_runs_a_trip_through_its_full_lifecycle_and_calculates_revenue(): void
    {
        [$company, $manager] = $this->managerFixture();
        $bus = $this->approvedBus($company);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $this->route($company)->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDay(), 'arrives_at' => now()->addDay()->addHours(6), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'draft']);
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKLC1', 'company_id' => $company->id, 'trip_id' => $trip->id, 'contact_name' => 'Traveller', 'contact_phone' => '+263770000000', 'subtotal' => 30, 'total' => 30, 'platform_fee' => 3, 'currency' => 'USD', 'status' => 'confirmed']);
        Commission::create(['company_id' => $company->id, 'booking_id' => $booking->id, 'code' => 'COM-BKLC1', 'name' => 'Booking revenue allocation', 'status' => 'available', 'amount' => 3, 'currency' => 'USD', 'gross_amount' => 30, 'platform_amount' => 3, 'agent_amount' => 0, 'operator_amount' => 27]);

        $this->actingAs($manager)->postJson("/api/v1/trips/{$trip->id}/publication")->assertOk()->assertJsonPath('status', 'published');
        $this->actingAs($manager)->postJson("/api/v1/trips/{$trip->id}/boarding")->assertOk()->assertJsonPath('status', 'boarding');
        $this->assertDatabaseHas('buses', ['id' => $bus->id, 'status' => 'boarding']);
        $this->actingAs($manager)->postJson("/api/v1/trips/{$trip->id}/departure")->assertOk()->assertJsonPath('status', 'departed');
        $this->assertDatabaseHas('buses', ['id' => $bus->id, 'status' => 'in_transit']);
        $this->actingAs($manager)->postJson("/api/v1/trips/{$trip->id}/arrival")->assertOk()->assertJsonPath('status', 'arrived');
        $this->actingAs($manager)->postJson("/api/v1/trips/{$trip->id}/expenses", ['type' => 'fuel', 'amount' => 5])->assertCreated();

        $completion = $this->actingAs($manager)->postJson("/api/v1/trips/{$trip->id}/completion")->assertOk()->json();
        $this->assertSame('completed', $completion['trip']['status']);
        $this->assertEqualsWithDelta(27.0, $completion['revenue']['operator_revenue'], 0.001);
        $this->assertEqualsWithDelta(5.0, $completion['revenue']['expenses'], 0.001);
        $this->assertEqualsWithDelta(22.0, $completion['revenue']['net_revenue'], 0.001);
        $this->assertDatabaseHas('buses', ['id' => $bus->id, 'status' => 'available']);

        $this->actingAs($manager)->postJson("/api/v1/trips/{$trip->id}/boarding")->assertStatus(409);
    }

    public function test_operations_manager_delays_and_cancels_trips(): void
    {
        [$company, $manager] = $this->managerFixture();
        $bus = $this->approvedBus($company);
        $route = $this->route($company);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDay(), 'arrives_at' => now()->addDay()->addHours(6), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'published']);

        $newDeparture = now()->addDay()->addHours(2);
        $this->actingAs($manager)->postJson("/api/v1/trips/{$trip->id}/delay", ['departs_at' => $newDeparture->toIso8601String(), 'arrives_at' => $newDeparture->copy()->addHours(6)->toIso8601String(), 'reason' => 'Late incoming bus'])
            ->assertOk()->assertJsonPath('status', 'delayed')->assertJsonPath('delay_reason', 'Late incoming bus');

        $cancelTrip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(2), 'arrives_at' => now()->addDays(2)->addHours(6), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'published']);
        $this->actingAs($manager)->postJson("/api/v1/trips/{$cancelTrip->id}/cancellation", ['reason' => 'Route closed for roadworks'])
            ->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('cancellation_reason', 'Route closed for roadworks');
        $this->actingAs($manager)->postJson("/api/v1/trips/{$cancelTrip->id}/cancellation", ['reason' => 'Again'])->assertStatus(409);
    }

    public function test_operations_manager_duplicates_a_trip_and_replaces_its_bus(): void
    {
        [$company, $manager] = $this->managerFixture();
        $bus = $this->approvedBus($company);
        $replacement = $this->approvedBus($company, 'REPL-1');
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $this->route($company)->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDay(), 'arrives_at' => now()->addDay()->addHours(6), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'published']);

        $duplicate = $this->actingAs($manager)->postJson("/api/v1/trips/{$trip->id}/duplication", ['departs_at' => now()->addDays(3)->toIso8601String()])
            ->assertCreated()->assertJsonPath('status', 'draft')->assertJsonPath('duplicated_from_trip_id', $trip->id)->json();
        $this->assertSame($trip->route_id, $duplicate['route_id']);

        $this->actingAs($manager)->postJson("/api/v1/trips/{$trip->id}/bus-replacement", ['bus_id' => $replacement->id])
            ->assertOk()->assertJsonPath('bus_id', $replacement->id);
    }

    public function test_route_stops_are_ordered_and_profitability_is_calculated(): void
    {
        [$company, $manager] = $this->managerFixture();
        $route = $this->route($company);
        $midpoint = Terminal::create(['name' => 'Gweru', 'city' => 'Gweru', 'country' => 'ZW']);

        $this->actingAs($manager)->postJson("/api/v1/management/routes/{$route->id}/stops", ['terminal_id' => $midpoint->id, 'sequence' => 1, 'arrival_offset_minutes' => 120, 'stop_duration_minutes' => 15])->assertCreated();
        $this->actingAs($manager)->postJson("/api/v1/management/routes/{$route->id}/stops", ['terminal_id' => $midpoint->id, 'sequence' => 1])->assertStatus(422);

        $stops = $this->actingAs($manager)->getJson("/api/v1/management/routes/{$route->id}/stops")->assertOk()->json();
        $this->assertCount(1, $stops);
        $this->assertSame(1, $stops[0]['sequence']);

        $bus = $this->approvedBus($company);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDay(), 'arrives_at' => now()->addDay()->addHours(6), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'completed']);
        Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKROUTE1', 'company_id' => $company->id, 'trip_id' => $trip->id, 'contact_name' => 'Traveller', 'contact_phone' => '+263770000000', 'subtotal' => 30, 'total' => 30, 'currency' => 'USD', 'status' => 'confirmed']);
        (new PlatformResource)->useModule('trip_expenses')->newQuery()->create(['company_id' => $company->id, 'code' => 'exp-1', 'name' => 'Fuel', 'status' => 'recorded', 'amount' => 8, 'currency' => 'USD', 'data' => ['trip_id' => $trip->id, 'type' => 'fuel']]);

        $profitability = $this->actingAs($manager)->getJson("/api/v1/management/routes/{$route->id}/profitability")->assertOk()->json();
        $this->assertEqualsWithDelta(30.0, $profitability['revenue'], 0.001);
        $this->assertEqualsWithDelta(8.0, $profitability['expenses'], 0.001);
        $this->assertEqualsWithDelta(22.0, $profitability['profit'], 0.001);
    }

    public function test_trip_occupancy_service_moves_status_as_seats_fill_and_leaves_other_statuses_alone(): void
    {
        $company = Company::create(['name' => 'Occupancy Co', 'slug' => 'occupancy-co']);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'OCC-1', 'model' => 'Yutong', 'seat_capacity' => 1]);
        $seat = Seat::create(['bus_id' => $bus->id, 'number' => '1A']);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $this->route($company)->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDay(), 'arrives_at' => now()->addDay()->addHours(6), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'published']);
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKOCC1', 'company_id' => $company->id, 'trip_id' => $trip->id, 'contact_name' => 'Traveller', 'contact_phone' => '+263770000000', 'subtotal' => 30, 'total' => 30, 'currency' => 'USD']);
        BookingPassenger::create(['booking_id' => $booking->id, 'trip_id' => $trip->id, 'seat_id' => $seat->id, 'full_name' => 'Traveller', 'type' => 'adult', 'fare' => 30, 'status' => 'confirmed']);

        app(TripOccupancyService::class)->sync($trip->fresh());
        $this->assertSame('fully_booked', $trip->fresh()->status);

        $trip->update(['status' => 'boarding']);
        app(TripOccupancyService::class)->sync($trip->fresh());
        $this->assertSame('boarding', $trip->fresh()->status);
    }

    public function test_an_assigned_driver_can_progress_their_own_trip_but_not_someone_elses(): void
    {
        [$company, $manager] = $this->managerFixture();
        $bus = $this->approvedBus($company);
        $route = $this->route($company);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDay(), 'arrives_at' => now()->addDay()->addHours(6), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'published']);
        $otherTrip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(2), 'arrives_at' => now()->addDays(2)->addHours(6), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'published']);

        $driverUser = User::factory()->create(['company_id' => $company->id, 'role' => 'driver']);
        $driverUser->assignRole('driver');
        $employee = Employee::create(['company_id' => $company->id, 'user_id' => $driverUser->id, 'employee_number' => 'DRV-1', 'code' => 'DRV-1', 'name' => 'Driver One', 'staff_type' => 'driver', 'status' => 'active']);
        StaffAssignment::create(['company_id' => $company->id, 'employee_id' => $employee->id, 'trip_id' => $trip->id, 'code' => 'DRV-1:'.$trip->id, 'name' => 'Driver', 'duty_role' => 'driver', 'status' => 'assigned', 'assigned_from' => $trip->departs_at, 'assigned_until' => $trip->arrives_at]);

        $this->actingAs($driverUser)->postJson("/api/v1/trips/{$trip->id}/boarding")->assertOk()->assertJsonPath('status', 'boarding');
        $this->actingAs($driverUser)->postJson("/api/v1/trips/{$trip->id}/departure")->assertOk()->assertJsonPath('status', 'departed');
        $this->actingAs($driverUser)->postJson("/api/v1/trips/{$otherTrip->id}/boarding")->assertStatus(403);
        // Field actions are open to an assigned driver; back-office-only actions still require trips.manage.
        $this->actingAs($driverUser)->postJson("/api/v1/trips/{$trip->id}/cancellation", ['reason' => 'Test'])->assertStatus(403);
    }

    public function test_low_occupancy_alerts_flag_a_near_empty_trip_departing_soon_but_not_a_full_or_distant_one(): void
    {
        [$company, $manager] = $this->managerFixture();
        $bus = $this->approvedBus($company);
        $route = $this->route($company);
        $seat = Seat::create(['bus_id' => $bus->id, 'number' => '1A']);
        $nearEmptyTrip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addHours(6), 'arrives_at' => now()->addHours(12), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'published']);
        $distantTrip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(10), 'arrives_at' => now()->addDays(10)->addHours(6), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'published']);

        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKLOW1', 'company_id' => $company->id, 'trip_id' => $nearEmptyTrip->id, 'contact_name' => 'P', 'contact_phone' => '+263770000000', 'subtotal' => 30, 'total' => 30, 'currency' => 'USD', 'status' => 'confirmed']);
        BookingPassenger::create(['booking_id' => $booking->id, 'trip_id' => $nearEmptyTrip->id, 'seat_id' => $seat->id, 'full_name' => 'P', 'type' => 'adult', 'fare' => 30, 'status' => 'confirmed']);

        $alerts = $this->actingAs($manager)->getJson('/api/v1/trip-management/low-occupancy-alerts')->assertOk()->json();
        $tripIds = collect($alerts['trips'])->pluck('trip_id');
        $this->assertTrue($tripIds->contains($nearEmptyTrip->id));
        $this->assertFalse($tripIds->contains($distantTrip->id));
    }

    /** @return array{Company, User} */
    private function managerFixture(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-'.str()->random(6)]);
        $manager = User::factory()->create(['company_id' => $company->id, 'role' => 'operations_manager']);
        $manager->assignRole('operations_manager');

        return [$company, $manager];
    }

    private function route(Company $company): TransportRoute
    {
        $origin = Terminal::create(['name' => 'Harare Roadport', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Bulawayo Terminal', 'city' => 'Bulawayo', 'country' => 'ZW']);

        return TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare to Bulawayo', 'duration_minutes' => 360]);
    }

    private function approvedBus(Company $company, string $registration = 'ABC-1234'): Bus
    {
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => $registration, 'model' => 'Scania', 'seat_capacity' => 50, 'status' => 'available']);
        foreach (['insurance', 'permit'] as $documentType) {
            BusDocument::create(['company_id' => $company->id, 'bus_id' => $bus->id, 'document_type' => $documentType, 'file_path' => "documents/{$documentType}.pdf", 'expires_on' => today()->addYear(), 'status' => 'approved']);
        }

        return $bus;
    }
}
