<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\Company;
use App\Models\Seat;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RouteCommissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_routes_commission_tier_overrides_the_flat_rate_and_falls_back_when_no_tier_matches(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-route-commission', 'settings' => ['commission_rate' => 5]]);
        $finance = User::factory()->create(['company_id' => $company->id, 'role' => 'finance_manager']);
        $finance->assignRole('finance_manager');
        $origin = Terminal::create(['name' => 'Harare', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Mutare', 'city' => 'Mutare', 'country' => 'ZW']);
        $premiumRoute = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Premium Route', 'duration_minutes' => 240]);
        $plainRoute = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Plain Route', 'duration_minutes' => 240]);

        Sanctum::actingAs($finance);
        $this->putJson("/api/v1/management/routes/{$premiumRoute->id}/commission", ['commission_tiers' => [
            ['min_amount' => 0, 'max_amount' => 50, 'rate_percent' => 5],
            ['min_amount' => 50, 'max_amount' => null, 'rate_percent' => 15],
        ]])->assertOk()->assertJsonCount(2, 'commission_tiers');

        // Premium route, $100 fare: falls in the 15% tier, not the company's flat 5%.
        $premiumTrip = $this->tripFor($company, $premiumRoute, 100);
        $premiumQuote = $this->postJson("/api/v1/trips/{$premiumTrip->id}/quote", ['passengers' => [['type' => 'adult']]])->assertOk()->json();
        $this->assertEqualsWithDelta(15.0, $premiumQuote['platform_fee'], 0.001);

        // Plain route has no tiers configured at all: falls back to the company's flat 5%.
        $plainTrip = $this->tripFor($company, $plainRoute, 100);
        $plainQuote = $this->postJson("/api/v1/trips/{$plainTrip->id}/quote", ['passengers' => [['type' => 'adult']]])->assertOk()->json();
        $this->assertEqualsWithDelta(5.0, $plainQuote['platform_fee'], 0.001);
    }

    public function test_only_finance_or_company_management_staff_can_configure_route_commission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-route-commission-auth']);
        $terminalOfficer = User::factory()->create(['company_id' => $company->id, 'role' => 'terminal_officer']);
        $terminalOfficer->assignRole('terminal_officer');
        $origin = Terminal::create(['name' => 'Harare', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Mutare', 'city' => 'Mutare', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Route', 'duration_minutes' => 240]);

        Sanctum::actingAs($terminalOfficer);
        $this->putJson("/api/v1/management/routes/{$route->id}/commission", ['commission_tiers' => [['min_amount' => 0, 'max_amount' => null, 'rate_percent' => 10]]])->assertNotFound();
    }

    private function tripFor(Company $company, TransportRoute $route, float $baseFare): Trip
    {
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'RC-'.str()->random(6), 'model' => 'Scania', 'seat_capacity' => 1]);
        Seat::create(['bus_id' => $bus->id, 'number' => '1A']);

        return Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(2), 'arrives_at' => now()->addDays(2)->addHours(4), 'base_fare' => $baseFare, 'currency' => 'USD', 'status' => 'published']);
    }
}
