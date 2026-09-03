<?php

namespace Tests\Feature;

use App\Jobs\DeliverPlatformNotification;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Seat;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CorporateAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_corporate_account_lifecycle_from_registration_to_invoicing(): void
    {
        Queue::fake([DeliverPlatformNotification::class]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-corp']);
        $staff = User::factory()->create(['company_id' => $company->id, 'role' => 'company_administrator']);
        $staff->assignRole('company_administrator');
        $orgAdmin = User::factory()->create(['role' => 'passenger']);

        [$trip, $seats] = $this->tripFixture($company);

        Sanctum::actingAs($orgAdmin);
        $accountId = $this->postJson('/api/v1/corporate/accounts', ['company_id' => $company->id, 'name' => 'Acme Corp', 'billing_email' => 'billing@acme.test', 'billing_phone' => '+263770000000'])
            ->assertCreated()->assertJsonPath('status', 'pending')->json('id');

        // Members cannot be added before verification.
        $this->postJson("/api/v1/corporate/accounts/{$accountId}/members", ['name' => 'Employee One', 'member_type' => 'employee'])->assertStatus(409);

        Sanctum::actingAs($staff);
        $this->postJson("/api/v1/corporate/accounts/{$accountId}/verification", ['decision' => 'verified'])->assertOk()->assertJsonPath('status', 'verified');
        $this->putJson("/api/v1/corporate/accounts/{$accountId}/credit-limit", ['credit_limit' => 250, 'negotiated_discount_percent' => 10])->assertOk()->assertJsonPath('credit_limit', '250.00');

        Sanctum::actingAs($orgAdmin);
        $costCentreId = $this->postJson("/api/v1/corporate/accounts/{$accountId}/cost-centres", ['name' => 'Sales Dept'])->assertCreated()->json('id');
        $this->postJson("/api/v1/corporate/accounts/{$accountId}/members", ['name' => 'Employee One', 'member_type' => 'employee', 'cost_centre_id' => $costCentreId])->assertCreated()->assertJsonPath('member_type', 'employee');

        // $100 base fare x 2 passengers = $200, minus the 10% negotiated rate = $180, within the $250 limit.
        $requestId = $this->postJson("/api/v1/corporate/accounts/{$accountId}/booking-requests", [
            'trip_id' => $trip->id, 'cost_centre_id' => $costCentreId,
            'passengers' => [
                ['seat_id' => $seats[0]->id, 'full_name' => 'Employee One', 'phone' => '+263771111111', 'email' => 'e1@acme.test', 'type' => 'adult'],
                ['seat_id' => $seats[1]->id, 'full_name' => 'Employee Two', 'phone' => '+263772222222', 'email' => 'e2@acme.test', 'type' => 'adult'],
            ],
        ])->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('estimated_total', '180.00')->json('id');

        Sanctum::actingAs($staff);
        $decision = $this->postJson("/api/v1/corporate/accounts/{$accountId}/booking-requests/{$requestId}/decision", ['decision' => 'approved'])->assertOk()->json();
        $this->assertSame('booked', $decision['status']);
        $bookingId = $decision['booking_id'];
        // The negotiated rate applies to the real booking, not just the estimate.
        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'status' => 'confirmed', 'total' => 180.00, 'corporate_account_id' => $accountId, 'cost_centre_id' => $costCentreId]);
        $this->assertDatabaseHas('tickets', []);
        $this->assertDatabaseHas('corporate_accounts', ['id' => $accountId, 'outstanding_balance' => 180.00]);

        $this->postJson("/api/v1/corporate/accounts/{$accountId}/invoices", ['period_start' => today()->toDateString(), 'period_end' => today()->toDateString()])
            ->assertCreated()->assertJsonPath('total', '180.00')->assertJsonPath('status', 'issued');
        $invoiceId = $this->getJson("/api/v1/corporate/accounts/{$accountId}/invoices")->assertOk()->json('0.id');

        Sanctum::actingAs($orgAdmin);
        $this->postJson("/api/v1/corporate/accounts/{$accountId}/wallet/deposits", ['amount' => 200])->assertCreated()->assertJsonPath('balance', '200.00');
        $this->postJson("/api/v1/corporate/accounts/{$accountId}/invoices/{$invoiceId}/payment", ['method' => 'wallet'])->assertOk()->assertJsonPath('status', 'paid');

        $statement = $this->getJson("/api/v1/corporate/accounts/{$accountId}/statement")->assertOk()->json();
        $this->assertEqualsWithDelta(0.0, (float) $statement['outstanding_balance'], 0.001);
        $this->assertEqualsWithDelta(20.0, $statement['wallet_balance'], 0.001);
        $this->assertEqualsWithDelta(250.0, $statement['available_credit'], 0.001);

        $report = $this->getJson("/api/v1/corporate/accounts/{$accountId}/report")->assertOk()->json();
        $this->assertSame(1, $report['trips_taken']);
        $this->assertEqualsWithDelta(180.0, $report['total_spend'], 0.001);
        $this->assertEqualsWithDelta(180.0, $report['spend_by_cost_centre'][$costCentreId], 0.001);
    }

    public function test_booking_request_exceeding_credit_limit_is_rejected(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-credit']);
        $staff = User::factory()->create(['company_id' => $company->id, 'role' => 'company_administrator']);
        $staff->assignRole('company_administrator');
        $orgAdmin = User::factory()->create(['role' => 'passenger']);
        [$trip, $seats] = $this->tripFixture($company);

        Sanctum::actingAs($orgAdmin);
        $accountId = $this->postJson('/api/v1/corporate/accounts', ['company_id' => $company->id, 'name' => 'Tight Budget Corp', 'billing_email' => 'billing@budget.test', 'billing_phone' => '+263770000001'])->assertCreated()->json('id');
        Sanctum::actingAs($staff);
        $this->postJson("/api/v1/corporate/accounts/{$accountId}/verification", ['decision' => 'verified'])->assertOk();
        $this->putJson("/api/v1/corporate/accounts/{$accountId}/credit-limit", ['credit_limit' => 50])->assertOk();

        Sanctum::actingAs($orgAdmin);
        $this->postJson("/api/v1/corporate/accounts/{$accountId}/booking-requests", [
            'trip_id' => $trip->id,
            'passengers' => [['seat_id' => $seats[0]->id, 'full_name' => 'Employee One', 'phone' => '+263771111111', 'email' => 'e1@budget.test', 'type' => 'adult']],
        ])->assertUnprocessable();
    }

    public function test_a_stranger_cannot_administer_someone_elses_corporate_account(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-isolation']);
        $orgAdmin = User::factory()->create(['role' => 'passenger']);
        $stranger = User::factory()->create(['role' => 'passenger']);

        Sanctum::actingAs($orgAdmin);
        $accountId = $this->postJson('/api/v1/corporate/accounts', ['company_id' => $company->id, 'name' => 'Private Corp', 'billing_email' => 'billing@private.test', 'billing_phone' => '+263770000002'])->assertCreated()->json('id');

        Sanctum::actingAs($stranger);
        $this->postJson("/api/v1/corporate/accounts/{$accountId}/cost-centres", ['name' => 'Should Fail'])->assertForbidden();
        $this->getJson("/api/v1/corporate/accounts/{$accountId}")->assertNotFound();
    }

    /** @return array{Trip, Collection<int, Seat>} */
    private function tripFixture(Company $company): array
    {
        $origin = Terminal::create(['name' => 'Harare', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Mutare', 'city' => 'Mutare', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare-Mutare', 'duration_minutes' => 240]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'CORP-'.str()->random(6), 'model' => 'Scania', 'seat_capacity' => 10]);
        $seats = collect(['1A', '1B', '1C'])->map(fn (string $number) => Seat::create(['bus_id' => $bus->id, 'number' => $number]));
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(3), 'arrives_at' => now()->addDays(3)->addHours(4), 'base_fare' => 100, 'currency' => 'USD', 'status' => 'published']);

        return [$trip, $seats];
    }
}
