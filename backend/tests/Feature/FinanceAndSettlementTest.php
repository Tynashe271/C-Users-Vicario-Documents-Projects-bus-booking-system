<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use App\Services\FinanceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceAndSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_booking_is_allocated_once_and_paid_through_separated_settlement_approval(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [$company, $booking, $payment] = $this->financeFixture();
        $finance = app(FinanceService::class);
        $commission = $finance->allocateConfirmedBooking($booking->load('trip.company'), $payment);
        $finance->allocateConfirmedBooking($booking->load('trip.company'), $payment);
        $this->assertDatabaseCount('commissions', 1);
        $this->assertDatabaseHas('commissions', ['id' => $commission->id, 'gross_amount' => 110, 'platform_amount' => 10, 'operator_amount' => 100]);
        $this->assertDatabaseHas('wallets', ['company_id' => $company->id, 'wallet_type' => 'operator', 'balance' => 100, 'held_balance' => 100]);

        [$creator, $approver, $payer] = collect(range(1, 3))->map(function () use ($company): User {
            $user = User::factory()->create(['company_id' => $company->id, 'role' => 'finance_manager']);
            $user->assignRole('finance_manager');

            return $user;
        })->all();
        $settlementId = $this->actingAs($creator)->postJson('/api/v1/finance/settlements', ['period_start' => today()->toDateString(), 'period_end' => today()->toDateString(), 'currency' => 'USD'])->assertCreated()->assertJsonPath('net_amount', '100.00')->json('id');
        $this->actingAs($creator)->postJson("/api/v1/finance/settlements/{$settlementId}/approve")->assertConflict();
        $this->actingAs($approver)->postJson("/api/v1/finance/settlements/{$settlementId}/approve")->assertOk()->assertJsonPath('status', 'approved');
        $this->actingAs($approver)->postJson("/api/v1/finance/settlements/{$settlementId}/pay", ['payment_reference' => 'BANK-SET-1'])->assertConflict();
        $this->actingAs($payer)->postJson("/api/v1/finance/settlements/{$settlementId}/pay", ['payment_reference' => 'BANK-SET-1'])->assertOk()->assertJsonPath('status', 'paid');

        $this->assertDatabaseHas('wallets', ['company_id' => $company->id, 'wallet_type' => 'operator', 'balance' => 0, 'held_balance' => 0]);
        $this->assertDatabaseHas('commissions', ['booking_id' => $booking->id, 'status' => 'settled']);
        $this->actingAs($payer)->getJson('/api/v1/finance/dashboard')->assertOk()->assertJsonPath('gross_booking_value', 110)->assertJsonPath('unsettled_amount', 0);
    }

    public function test_reconciliation_reports_provider_difference_and_is_tenant_scoped(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [$company] = $this->financeFixture();
        $manager = User::factory()->create(['company_id' => $company->id, 'role' => 'finance_manager']);
        $manager->assignRole('finance_manager');
        $this->actingAs($manager)->postJson('/api/v1/finance/reconciliations', ['provider' => 'paynow', 'date' => today()->toDateString(), 'reported_amount' => 105, 'reported_transactions' => 1])->assertCreated()->assertJsonPath('status', 'exception')->assertJsonPath('difference_amount', '-5.00');

        $other = Company::create(['name' => 'Other', 'slug' => 'other']);
        $otherManager = User::factory()->create(['company_id' => $other->id, 'role' => 'finance_manager']);
        $otherManager->assignRole('finance_manager');
        $this->actingAs($otherManager)->getJson('/api/v1/finance/dashboard')->assertOk()->assertJsonPath('gross_booking_value', 0);
    }

    /** @return array{Company, Booking, Payment} */
    private function financeFixture(): array
    {
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star']);
        $origin = Terminal::create(['name' => 'Roadport', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Intercity', 'city' => 'Bulawayo', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare to Bulawayo', 'duration_minutes' => 360]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'FIN123', 'model' => 'Scania', 'seat_capacity' => 50]);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDay(), 'arrives_at' => now()->addDay()->addHours(6), 'base_fare' => 100, 'currency' => 'USD', 'status' => 'available']);
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKFIN001', 'company_id' => $company->id, 'trip_id' => $trip->id, 'contact_name' => 'Passenger', 'contact_phone' => '0771', 'subtotal' => 100, 'platform_fee' => 10, 'total' => 110, 'currency' => 'USD', 'status' => 'confirmed']);
        $payment = Payment::create(['booking_id' => $booking->id, 'provider' => 'paynow', 'provider_reference' => 'PAY-FIN-1', 'idempotency_key' => 'finance-test-payment', 'amount' => 110, 'currency' => 'USD', 'status' => 'paid', 'paid_at' => now()]);

        return [$company, $booking, $payment];
    }
}
