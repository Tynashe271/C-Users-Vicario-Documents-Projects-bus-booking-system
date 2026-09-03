<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Bus;
use App\Models\Commission;
use App\Models\Company;
use App\Models\Parcel;
use App\Models\PlatformResource;
use App\Models\Seat;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GlobalReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_administrator_sees_platform_wide_revenue_and_performance_report(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['role' => 'super_administrator']);
        $admin->assignRole('super_administrator');

        $company = Company::create(['name' => 'Report Co', 'slug' => 'report-co']);
        $origin = Terminal::create(['name' => 'Harare', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Mutare', 'city' => 'Mutare', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare-Mutare', 'duration_minutes' => 240]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'RPT-1', 'model' => 'Scania', 'seat_capacity' => 2]);
        $seat = Seat::create(['bus_id' => $bus->id, 'number' => '1A']);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->subDay(), 'arrives_at' => now()->subDay()->addHours(4), 'base_fare' => 100, 'currency' => 'USD', 'status' => 'completed']);

        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKRPT1', 'company_id' => $company->id, 'trip_id' => $trip->id, 'contact_name' => 'Passenger', 'contact_phone' => '+263770000000', 'subtotal' => 100, 'total' => 100, 'platform_fee' => 10, 'taxes' => 5, 'currency' => 'USD', 'status' => 'confirmed']);
        $booking->passengers()->create(['trip_id' => $trip->id, 'seat_id' => $seat->id, 'full_name' => 'Passenger', 'type' => 'adult', 'fare' => 100, 'status' => 'confirmed']);
        Commission::create(['company_id' => $company->id, 'booking_id' => $booking->id, 'code' => 'COM-RPT1', 'name' => 'Allocation', 'status' => 'available', 'amount' => 10, 'currency' => 'USD', 'gross_amount' => 100, 'platform_amount' => 10, 'agent_amount' => 0, 'operator_amount' => 90]);

        (new PlatformResource)->useModule('refunds')->newQuery()->create(['company_id' => $company->id, 'code' => 'refund-rpt-1', 'name' => 'Refund', 'status' => 'approved', 'amount' => 20, 'currency' => 'USD', 'data' => ['booking_id' => $booking->id]]);
        Parcel::create(['company_id' => $company->id, 'route_id' => $route->id, 'sender_name' => 'A', 'sender_phone' => '1', 'receiver_name' => 'B', 'receiver_phone' => '2', 'description' => 'Box', 'weight_kg' => 5, 'status' => 'collected', 'amount' => 15, 'currency' => 'USD']);

        Sanctum::actingAs($admin);
        $report = $this->getJson('/api/v1/reports/platform')->assertOk()->json();

        $this->assertSame(1, $report['total_bookings']);
        $this->assertEqualsWithDelta(100.0, $report['gross_booking_value'], 0.001);
        $this->assertEqualsWithDelta(10.0, $report['platform_revenue'], 0.001);
        $this->assertEqualsWithDelta(90.0, $report['operator_revenue'], 0.001);
        $this->assertEqualsWithDelta(20.0, $report['total_refunded'], 0.001);
        $this->assertEqualsWithDelta(20.0, $report['refund_rate_percent'], 0.001);
        $this->assertSame('Report Co', $report['revenue_by_company'][0]['company']);
        $this->assertSame('Harare-Mutare', $report['revenue_by_route'][0]['route']);
        $this->assertSame(1, $report['parcel_performance']['total']);
        $this->assertEqualsWithDelta(15.0, $report['parcel_performance']['revenue'], 0.001);
        $this->assertEqualsWithDelta(50.0, $report['seat_occupancy_percent'], 0.001);

        $export = $this->getJson('/api/v1/reports/platform/revenue-by-company/export')->assertOk()->json();
        $this->assertStringContainsString('Report Co', $export['csv']);
    }

    public function test_company_scoped_staff_cannot_view_the_platform_wide_report(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Scoped Co', 'slug' => 'scoped-co']);
        $manager = User::factory()->create(['company_id' => $company->id, 'role' => 'operations_manager']);
        $manager->assignRole('operations_manager');
        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/reports/platform')->assertForbidden();
    }
}
