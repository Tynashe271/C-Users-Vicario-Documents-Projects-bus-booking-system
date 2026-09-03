<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Bus;
use App\Models\Commission;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParcelCommissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_parcel_payment_allocates_commission_at_the_operators_flat_rate_when_no_tiers_are_configured(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [$company, $route] = $this->routeFixture(['commission_rate' => 10]);
        $clerk = User::factory()->create(['company_id' => $company->id, 'role' => 'company_administrator']);
        $clerk->assignRole('company_administrator');

        $created = $this->actingAs($clerk)->postJson('/api/v1/parcels', $this->parcelPayload($route->id))->assertCreated();
        $parcelId = $created->json('parcel.id');
        $amount = (float) $created->json('parcel.amount');

        $this->actingAs($clerk)->postJson("/api/v1/parcels/{$parcelId}/payment", ['payment_reference' => 'PAY-1', 'amount' => $amount])->assertOk();

        $expectedPlatform = round($amount * 0.10, 2);
        $this->assertDatabaseHas('commissions', ['parcel_id' => $parcelId, 'gross_amount' => $amount, 'platform_amount' => $expectedPlatform]);
        $operatorWallet = Wallet::where('company_id', $company->id)->where('wallet_type', 'operator')->first();
        $this->assertEqualsWithDelta($amount - $expectedPlatform, (float) $operatorWallet->held_balance, 0.01);

        $report = $this->actingAs($clerk)->getJson('/api/v1/parcels/report')->assertOk()->json();
        $this->assertEqualsWithDelta($expectedPlatform, $report['platform_commission'], 0.01);
    }

    public function test_platform_staff_configures_parcel_commission_tiers_and_the_matching_tier_applies(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [$company, $route] = $this->routeFixture(['commission_rate' => 10]);
        $admin = User::factory()->create(['role' => 'super_administrator']);
        $admin->assignRole('super_administrator');
        $clerk = User::factory()->create(['company_id' => $company->id, 'role' => 'company_administrator']);
        $clerk->assignRole('company_administrator');

        $this->actingAs($admin)->patchJson("/api/v1/admin/companies/{$company->id}/commission", [
            'parcel_commission_tiers' => [
                ['min_amount' => 0, 'max_amount' => 15, 'rate_percent' => 20],
                ['min_amount' => 15, 'max_amount' => null, 'rate_percent' => 5],
            ],
        ])->assertOk();

        // The route/weight fixture below quotes to $17.70, which lands in the 5% tier, not the
        // 10% flat rate or the 20% low-value tier — proving the tier lookup, not just the fallback.
        $created = $this->actingAs($clerk)->postJson('/api/v1/parcels', $this->parcelPayload($route->id))->assertCreated();
        $amount = (float) $created->json('parcel.amount');
        $this->assertEqualsWithDelta(17.70, $amount, 0.001);
        $parcelId = $created->json('parcel.id');

        $this->actingAs($clerk)->postJson("/api/v1/parcels/{$parcelId}/payment", ['payment_reference' => 'PAY-2', 'amount' => $amount])->assertOk();
        $this->assertDatabaseHas('commissions', ['parcel_id' => $parcelId, 'platform_amount' => round($amount * 0.05, 2)]);
    }

    public function test_parcel_commission_flows_into_the_same_settlement_as_ticket_commission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [$company, $route] = $this->routeFixture(['commission_rate' => 10]);
        $clerk = User::factory()->create(['company_id' => $company->id, 'role' => 'company_administrator']);
        $clerk->assignRole('company_administrator');
        $finance = User::factory()->create(['company_id' => $company->id, 'role' => 'finance_manager']);
        $finance->assignRole('finance_manager');

        $created = $this->actingAs($clerk)->postJson('/api/v1/parcels', $this->parcelPayload($route->id))->assertCreated();
        $parcelId = $created->json('parcel.id');
        $amount = (float) $created->json('parcel.amount');
        $this->actingAs($clerk)->postJson("/api/v1/parcels/{$parcelId}/payment", ['payment_reference' => 'PAY-3', 'amount' => $amount])->assertOk();

        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'CMSN-1', 'model' => 'Scania', 'seat_capacity' => 1]);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDay(), 'arrives_at' => now()->addDay()->addHours(6), 'base_fare' => 100, 'currency' => 'USD', 'status' => 'published']);
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKCMSN1', 'company_id' => $company->id, 'trip_id' => $trip->id, 'contact_name' => 'P', 'contact_phone' => '+263770000000', 'subtotal' => 100, 'total' => 100, 'platform_fee' => 10, 'currency' => 'USD', 'status' => 'confirmed']);
        Commission::create(['company_id' => $company->id, 'booking_id' => $booking->id, 'code' => 'COM-CMSN1', 'name' => 'Booking revenue allocation', 'status' => 'available', 'amount' => 10, 'currency' => 'USD', 'gross_amount' => 100, 'platform_amount' => 10, 'agent_amount' => 0, 'operator_amount' => 90, 'available_at' => now()]);

        $settlement = $this->actingAs($finance)->postJson('/api/v1/finance/settlements', ['period_start' => today()->subDay()->toDateString(), 'period_end' => today()->addDay()->toDateString(), 'currency' => 'USD'])->assertCreated()->json();

        $this->assertCount(2, $settlement['items']);
        $this->assertTrue(collect($settlement['items'])->contains(fn (array $item): bool => str_starts_with($item['name'], 'Parcel ')));
        $this->assertTrue(collect($settlement['items'])->contains(fn (array $item): bool => $item['name'] === 'Booking BKCMSN1'));
    }

    /** @return array{Company, TransportRoute} */
    private function routeFixture(array $companySettings): array
    {
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-'.str()->random(6), 'settings' => $companySettings]);
        $origin = Terminal::create(['name' => 'Roadport', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Intercity', 'city' => 'Bulawayo', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare to Bulawayo', 'distance_km' => 440, 'duration_minutes' => 360]);

        return [$company, $route];
    }

    /** @return array<string, mixed> */
    private function parcelPayload(int $routeId): array
    {
        return ['route_id' => $routeId, 'sender_name' => 'Alice Sender', 'sender_phone' => '+263770000001', 'receiver_name' => 'Bob Receiver', 'receiver_phone' => '+263770000002', 'description' => 'Books', 'weight_kg' => 5, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 20, 'prohibited_items_declared' => true];
    }
}
