<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Seat;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GraphQlApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_trip_search_and_trip_details_are_decorated_exactly_like_the_rest_search(): void
    {
        [$company, $route, $bus, $trip] = $this->tripFixture();
        Seat::create(['bus_id' => $bus->id, 'number' => '1B']);

        $search = $this->graphql('{ tripSearch(origin_terminal_id: '.$route->origin_terminal_id.', destination_terminal_id: '.$route->destination_terminal_id.', date: "'.now()->addDay()->toDateString().'") { id available_seats operator_rating route { origin { name } destination { name } } bus { registration_number } } }')
            ->assertOk()->json('data.tripSearch');
        $this->assertCount(1, $search);
        $this->assertSame(2, $search[0]['available_seats']);
        $this->assertSame('Harare', $search[0]['route']['origin']['name']);

        $details = $this->graphql('{ tripDetails(id: '.$trip->id.') { id available_seats bus { seat_capacity seats { number availability } } company { name } } }')
            ->assertOk()->json('data.tripDetails');
        $this->assertSame($company->name, $details['company']['name']);
        $this->assertCount(2, $details['bus']['seats']);
    }

    public function test_my_bookings_and_passenger_dashboard_only_ever_return_the_signed_in_users_own_data(): void
    {
        $this->postJson('/graphql', ['query' => '{ myBookings { reference } }'])->assertOk()->assertJsonPath('errors.0.message', 'Unauthenticated.');

        [$company, $route, $bus, $trip] = $this->tripFixture();
        $seat = Seat::first();
        $passenger = User::factory()->create();
        $stranger = User::factory()->create();
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKGQL1', 'company_id' => $company->id, 'trip_id' => $trip->id, 'user_id' => $passenger->id, 'contact_name' => 'P', 'contact_phone' => '+263770000000', 'subtotal' => 30, 'total' => 30, 'currency' => 'USD', 'status' => 'confirmed']);
        BookingPassenger::create(['booking_id' => $booking->id, 'trip_id' => $trip->id, 'seat_id' => $seat->id, 'full_name' => 'P', 'type' => 'adult', 'fare' => 30, 'status' => 'confirmed']);

        Sanctum::actingAs($passenger);
        $mine = $this->graphql('{ myBookings { reference total trip { id } } }')->assertOk()->json('data.myBookings');
        $this->assertCount(1, $mine);
        $this->assertSame('BKGQL1', $mine[0]['reference']);

        $dashboard = $this->graphql('{ passengerDashboard { unread_notifications wallet_balance upcoming_bookings { reference } } }')->assertOk()->json('data.passengerDashboard');
        $this->assertCount(1, $dashboard['upcoming_bookings']);
        $this->assertSame('BKGQL1', $dashboard['upcoming_bookings'][0]['reference']);

        Sanctum::actingAs($stranger);
        $this->assertCount(0, $this->graphql('{ myBookings { reference } }')->assertOk()->json('data.myBookings'));
    }

    public function test_company_dashboard_requires_reports_view_and_platform_dashboard_requires_platform_manage(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-graphql']);
        $manager = User::factory()->create(['company_id' => $company->id, 'role' => 'operations_manager']);
        $manager->assignRole('operations_manager');
        $driver = User::factory()->create(['company_id' => $company->id, 'role' => 'driver']);
        $driver->assignRole('driver');
        $admin = User::factory()->create(['role' => 'super_administrator']);
        $admin->assignRole('super_administrator');

        Sanctum::actingAs($manager);
        $companyJson = json_decode($this->graphql('{ companyDashboard { json } }')->assertOk()->json('data.companyDashboard.json'), true);
        $this->assertArrayHasKey('branches', $companyJson);

        Sanctum::actingAs($driver);
        $this->graphql('{ companyDashboard { json } }')->assertOk()->assertJsonPath('errors.0.message', 'Forbidden.');

        Sanctum::actingAs($admin);
        $platformJson = json_decode($this->graphql('{ platformDashboard { json } }')->assertOk()->json('data.platformDashboard.json'), true);
        $this->assertArrayHasKey('companies', $platformJson);

        $reportJson = json_decode($this->graphql('{ platformReport(from: "'.today()->subDays(30)->toDateString().'", to: "'.today()->toDateString().'") { json } }')->assertOk()->json('data.platformReport.json'), true);
        $this->assertArrayHasKey('total_bookings', $reportJson);

        Sanctum::actingAs($manager);
        $this->graphql('{ platformDashboard { json } }')->assertOk()->assertJsonPath('errors.0.message', 'Forbidden.');
    }

    private function graphql(string $query): TestResponse
    {
        return $this->postJson('/graphql', ['query' => $query]);
    }

    /** @return array{Company, TransportRoute, Bus, Trip} */
    private function tripFixture(): array
    {
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-graphql-'.Str::random(6)]);
        $origin = Terminal::create(['name' => 'Harare', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Bulawayo', 'city' => 'Bulawayo', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare to Bulawayo', 'duration_minutes' => 360]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'GQL-'.Str::random(6), 'model' => 'Scania', 'seat_capacity' => 2]);
        Seat::create(['bus_id' => $bus->id, 'number' => '1A']);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDay()->setTime(8, 0), 'arrives_at' => now()->addDay()->setTime(14, 0), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'available']);

        return [$company, $route, $bus, $trip];
    }
}
