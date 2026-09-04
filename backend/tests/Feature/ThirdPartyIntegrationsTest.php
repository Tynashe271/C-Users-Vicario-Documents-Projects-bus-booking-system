<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Bus;
use App\Models\Commission;
use App\Models\Company;
use App\Models\PlatformResource;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ErrorMonitoringService;
use App\Services\MappingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class ThirdPartyIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_error_monitoring_ignores_routine_rejections_but_forwards_and_logs_a_real_exception(): void
    {
        // A routine, expected rejection (the same class abort_if/abort_unless throws everywhere)
        // must never reach the monitor or the log — it's normal application flow, not an incident.
        app(ErrorMonitoringService::class)->report(ValidationException::withMessages(['x' => 'bad']));
        $this->assertDatabaseCount('integration_logs', 0);

        // An unconfigured monitor still records that a real exception happened, marked "skipped".
        app(ErrorMonitoringService::class)->report(new RuntimeException('Unexpected failure'));
        $this->assertDatabaseHas('integration_logs', ['status' => 'skipped']);

        // A configured monitor is actually called, and the log reflects that the call succeeded.
        config(['integrations.error_monitoring.url' => 'https://monitor.example.test/errors']);
        Http::fake(['monitor.example.test/*' => Http::response(['ok' => true], 200)]);
        app(ErrorMonitoringService::class)->report(new RuntimeException('Another failure'));
        Http::assertSent(fn ($request) => $request->url() === 'https://monitor.example.test/errors');
        $this->assertDatabaseHas('integration_logs', ['status' => 'sent']);
    }

    public function test_error_monitoring_never_throws_even_when_the_configured_endpoint_is_unreachable(): void
    {
        config(['integrations.error_monitoring.url' => 'https://monitor.example.test/errors']);
        Http::fake(['monitor.example.test/*' => fn () => throw new ConnectionException('down')]);

        app(ErrorMonitoringService::class)->report(new RuntimeException('Boom'));
        $this->assertDatabaseHas('integration_logs', ['status' => 'failed']);
    }

    public function test_mapping_service_falls_back_to_a_haversine_estimate_when_unconfigured_and_uses_a_configured_provider_when_set(): void
    {
        // 1 degree of longitude at the equator is a well-known, hand-verifiable distance.
        $km = app(MappingService::class)->distanceKm(0, 0, 0, 1);
        $this->assertEqualsWithDelta(111.19, $km, 0.1);

        config(['integrations.mapping.url' => 'https://maps.example.test/distance']);
        Http::fake(['maps.example.test/*' => Http::response(['distance_km' => 500], 200)]);
        $this->assertSame(500.0, app(MappingService::class)->distanceKm(0, 0, 0, 1));
    }

    public function test_route_creation_estimates_distance_when_not_supplied_and_never_overrides_an_explicit_value(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-mapping']);
        $manager = User::factory()->create(['company_id' => $company->id, 'role' => 'operations_manager']);
        $manager->assignRole('operations_manager');
        $origin = Terminal::create(['name' => 'Equator Point', 'city' => 'A', 'country' => 'ZW', 'latitude' => 0, 'longitude' => 0]);
        $destination = Terminal::create(['name' => 'One Degree East', 'city' => 'B', 'country' => 'ZW', 'latitude' => 0, 'longitude' => 1]);

        Sanctum::actingAs($manager);
        $estimated = $this->postJson('/api/v1/management/routes/records', ['origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Auto-distance route', 'duration_minutes' => 60])
            ->assertCreated()->json('data');
        $this->assertEqualsWithDelta(111, $estimated['distance_km'], 1);

        $explicit = $this->postJson('/api/v1/management/routes/records', ['origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Explicit-distance route', 'duration_minutes' => 60, 'distance_km' => 999])
            ->assertCreated()->json('data');
        $this->assertSame(999, $explicit['distance_km']);
    }

    public function test_a_paid_settlement_can_be_exported_to_accounting_and_the_attempt_is_logged(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-accounting']);
        $creator = User::factory()->create(['company_id' => $company->id, 'role' => 'finance_manager']);
        $creator->assignRole('finance_manager');
        $approver = User::factory()->create(['company_id' => $company->id, 'role' => 'finance_manager']);
        $approver->assignRole('finance_manager');
        $payer = User::factory()->create(['company_id' => $company->id, 'role' => 'finance_manager']);
        $payer->assignRole('finance_manager');

        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $this->routeFor($company)->id, 'bus_id' => $this->busFor($company)->id, 'departs_at' => now()->addDay(), 'arrives_at' => now()->addDay()->addHours(4), 'base_fare' => 100, 'currency' => 'USD', 'status' => 'completed']);
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKACC1', 'company_id' => $company->id, 'trip_id' => $trip->id, 'contact_name' => 'P', 'contact_phone' => '+263770000000', 'subtotal' => 100, 'total' => 100, 'currency' => 'USD', 'status' => 'confirmed']);
        Commission::create(['company_id' => $company->id, 'booking_id' => $booking->id, 'code' => 'COM-ACC1', 'name' => 'Allocation', 'status' => 'available', 'amount' => 10, 'currency' => 'USD', 'gross_amount' => 100, 'platform_amount' => 10, 'agent_amount' => 0, 'operator_amount' => 90, 'available_at' => now()]);
        // pay() debits the operator wallet FinanceService::allocateConfirmedBooking would normally
        // have created; since this test writes the Commission directly, it must exist too.
        Wallet::create(['company_id' => $company->id, 'code' => 'operator:USD', 'name' => 'Operator wallet', 'wallet_type' => 'operator', 'status' => 'active', 'currency' => 'USD', 'balance' => 90, 'held_balance' => 90, 'available_balance' => 0]);

        Sanctum::actingAs($creator);
        $settlementId = $this->postJson('/api/v1/finance/settlements', ['period_start' => today()->toDateString(), 'period_end' => today()->toDateString(), 'currency' => 'USD'])->assertCreated()->json('id');

        // Cannot export before it's actually paid.
        $this->postJson("/api/v1/finance/settlements/{$settlementId}/accounting-export")->assertStatus(409);

        Sanctum::actingAs($approver);
        $this->postJson("/api/v1/finance/settlements/{$settlementId}/approve")->assertOk();
        Sanctum::actingAs($payer);
        $this->postJson("/api/v1/finance/settlements/{$settlementId}/pay", ['payment_reference' => 'BANK-1'])->assertOk();

        $export = $this->postJson("/api/v1/finance/settlements/{$settlementId}/accounting-export")->assertOk()->json();
        $this->assertSame('skipped', $export['status']);
        $this->assertDatabaseHas('integration_logs', ['company_id' => $company->id, 'status' => 'skipped']);
        $log = (new PlatformResource)->useModule('integration_logs')->newQuery()->where('company_id', $company->id)->first();
        $this->assertSame($settlementId, (int) data_get($log->data, 'settlement_id'));
    }

    private function routeFor(Company $company): TransportRoute
    {
        $origin = Terminal::create(['name' => 'A '.Str::random(4), 'city' => 'A', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'B '.Str::random(4), 'city' => 'B', 'country' => 'ZW']);

        return TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Route '.Str::random(4), 'duration_minutes' => 240]);
    }

    private function busFor(Company $company): Bus
    {
        return Bus::create(['company_id' => $company->id, 'registration_number' => 'ACC-'.Str::random(6), 'model' => 'Scania', 'seat_capacity' => 1]);
    }
}
