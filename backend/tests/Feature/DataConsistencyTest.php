<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Bus;
use App\Models\Commission;
use App\Models\Company;
use App\Models\PlatformResource;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\Settlement;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\ConsistencyCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_wallet_whose_balance_disagrees_with_its_transaction_history_is_flagged(): void
    {
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-consistency-wallet']);
        $healthy = Wallet::create(['company_id' => $company->id, 'code' => 'w-healthy', 'wallet_type' => 'operator', 'status' => 'active', 'currency' => 'USD', 'balance' => 50, 'available_balance' => 50]);
        WalletTransaction::create(['company_id' => $company->id, 'wallet_id' => $healthy->id, 'code' => 'wt-1', 'name' => 'Credit', 'status' => 'posted', 'amount' => 50, 'currency' => 'USD', 'transaction_type' => 'deposit', 'direction' => 'credit', 'balance_after' => 50, 'idempotency_key' => 'wt-1', 'occurred_at' => now()]);
        $drifted = Wallet::create(['company_id' => $company->id, 'code' => 'w-drifted', 'wallet_type' => 'operator', 'status' => 'active', 'currency' => 'USD', 'balance' => 999, 'available_balance' => 999]);
        WalletTransaction::create(['company_id' => $company->id, 'wallet_id' => $drifted->id, 'code' => 'wt-2', 'name' => 'Credit', 'status' => 'posted', 'amount' => 50, 'currency' => 'USD', 'transaction_type' => 'deposit', 'direction' => 'credit', 'balance_after' => 999, 'idempotency_key' => 'wt-2', 'occurred_at' => now()]);

        $issues = app(ConsistencyCheckService::class)->run();

        $flagged = $issues->where('check', 'wallet_balance_drift');
        $this->assertCount(1, $flagged);
        $this->assertSame($drifted->id, $flagged->first()['context']['wallet_id']);
    }

    public function test_a_confirmed_booking_without_full_payment_is_flagged(): void
    {
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-consistency-booking']);
        $trip = $this->tripFor($company);
        $underpaid = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKCONS1', 'company_id' => $company->id, 'trip_id' => $trip->id, 'contact_name' => 'P', 'contact_phone' => '+263770000000', 'subtotal' => 100, 'total' => 100, 'currency' => 'USD', 'status' => 'confirmed']);
        $fullyPaid = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKCONS2', 'company_id' => $company->id, 'trip_id' => $trip->id, 'contact_name' => 'P', 'contact_phone' => '+263770000001', 'subtotal' => 100, 'total' => 100, 'currency' => 'USD', 'status' => 'confirmed']);
        $fullyPaid->payments()->create(['provider' => 'demo', 'idempotency_key' => 'pay-cons-1', 'amount' => 100, 'currency' => 'USD', 'status' => 'paid', 'paid_at' => now()]);

        $issues = app(ConsistencyCheckService::class)->run()->where('check', 'confirmed_booking_underpaid');

        $this->assertCount(1, $issues);
        $this->assertSame($underpaid->id, $issues->first()['context']['booking_id']);
    }

    public function test_a_pending_payment_booking_expired_well_past_its_deadline_is_flagged(): void
    {
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-consistency-stale']);
        $trip = $this->tripFor($company);
        $stale = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKCONS3', 'company_id' => $company->id, 'trip_id' => $trip->id, 'contact_name' => 'P', 'contact_phone' => '+263770000000', 'subtotal' => 100, 'total' => 100, 'currency' => 'USD', 'status' => 'pending_payment', 'payable_until' => now()->subMinutes(10)]);
        Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKCONS4', 'company_id' => $company->id, 'trip_id' => $trip->id, 'contact_name' => 'P', 'contact_phone' => '+263770000001', 'subtotal' => 100, 'total' => 100, 'currency' => 'USD', 'status' => 'pending_payment', 'payable_until' => now()->addMinutes(10)]);

        $issues = app(ConsistencyCheckService::class)->run()->where('check', 'stale_unreleased_booking');

        $this->assertCount(1, $issues);
        $this->assertSame($stale->id, $issues->first()['context']['booking_id']);
    }

    public function test_a_paid_settlement_with_an_unsettled_linked_commission_is_flagged(): void
    {
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-consistency-settlement']);
        $commission = Commission::create(['company_id' => $company->id, 'code' => 'COM-CONS1', 'name' => 'Allocation', 'status' => 'available', 'amount' => 10, 'currency' => 'USD', 'gross_amount' => 100, 'platform_amount' => 10, 'agent_amount' => 0, 'operator_amount' => 90, 'available_at' => now()]);
        $settlement = Settlement::create(['public_id' => Str::uuid(), 'company_id' => $company->id, 'code' => 'SET-CONS1', 'name' => 'Settlement', 'status' => 'paid', 'currency' => 'USD', 'period_start' => today(), 'period_end' => today(), 'gross_amount' => 100, 'platform_fees' => 10, 'agent_fees' => 0, 'net_amount' => 90, 'paid_at' => now()]);
        $settlement->items()->create(['company_id' => $company->id, 'commission_id' => $commission->id, 'code' => 'SET-CONS1:'.$commission->id, 'name' => 'Item', 'status' => 'paid', 'currency' => 'USD', 'gross_amount' => 100, 'fee_amount' => 10, 'net_amount' => 90]);

        $issues = app(ConsistencyCheckService::class)->run()->where('check', 'settlement_commission_mismatch');

        $this->assertCount(1, $issues);
        $this->assertSame($settlement->id, $issues->first()['context']['settlement_id']);
    }

    public function test_a_seat_lock_expired_well_past_the_cleanup_grace_period_is_flagged(): void
    {
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-consistency-lock']);
        $trip = $this->tripFor($company);
        $seat = Seat::first();
        $lock = SeatLock::create(['token' => Str::uuid(), 'trip_id' => $trip->id, 'seat_id' => $seat->id, 'expires_at' => now()->subMinutes(10)]);

        $issues = app(ConsistencyCheckService::class)->run()->where('check', 'expired_seat_lock_still_held');

        $this->assertCount(1, $issues);
        $this->assertSame($lock->id, $issues->first()['context']['seat_lock_id']);
    }

    public function test_the_console_command_logs_every_detected_issue_to_the_consistency_checks_module(): void
    {
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-consistency-command']);
        $wallet = Wallet::create(['company_id' => $company->id, 'code' => 'w-cmd', 'wallet_type' => 'operator', 'status' => 'active', 'currency' => 'USD', 'balance' => 500, 'available_balance' => 500]);
        // No matching transactions at all — balance is pure drift.
        $this->assertDatabaseCount('consistency_checks', 0);

        Artisan::call('system:check-consistency');

        $this->assertDatabaseHas('consistency_checks', ['company_id' => $company->id, 'status' => 'critical']);
        $log = (new PlatformResource)->useModule('consistency_checks')->newQuery()->where('company_id', $company->id)->first();
        $this->assertSame('wallet_balance_drift', data_get($log->data, 'check'));
        $this->assertSame($wallet->id, data_get($log->data, 'context.wallet_id'));
    }

    private function tripFor(Company $company): Trip
    {
        $origin = Terminal::create(['name' => 'A '.Str::random(4), 'city' => 'A', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'B '.Str::random(4), 'city' => 'B', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Route '.Str::random(4), 'duration_minutes' => 240]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'CON-'.Str::random(6), 'model' => 'Scania', 'seat_capacity' => 10]);
        Seat::create(['bus_id' => $bus->id, 'number' => '1A']);

        return Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(2), 'arrives_at' => now()->addDays(2)->addHours(4), 'base_fare' => 100, 'currency' => 'USD', 'status' => 'published']);
    }
}
