<?php

namespace Tests\Feature;

use App\Jobs\DeliverPlatformNotification;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Seat;
use App\Models\Terminal;
use App\Models\Ticket;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use App\Services\WalletService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RefundAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_officer_approves_an_original_method_refund_through_the_gateway(): void
    {
        Queue::fake([DeliverPlatformNotification::class]);
        [$company, $finance, $booking] = $this->cancelledBookingFixture('original');

        Sanctum::actingAs($finance);
        $refundId = $this->getJson('/api/v1/refunds?status=pending')->assertOk()->assertJsonPath('data.0.amount', '80.00')->json('data.0.id');
        $detail = $this->getJson("/api/v1/refunds/{$refundId}")->assertOk()->assertJsonPath('booking.id', $booking->id)->json();
        $this->assertEqualsWithDelta(80.0, $detail['policy_check']['refund_percent'], 0.001);

        $this->postJson("/api/v1/refunds/{$refundId}/approval")->assertOk()->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('payments', ['booking_id' => $booking->id, 'amount' => -80, 'status' => 'refund_pending']);

        // A decided refund cannot be approved again, and the report reflects it.
        $this->postJson("/api/v1/refunds/{$refundId}/approval")->assertStatus(409);
        $report = $this->getJson('/api/v1/refunds/report')->assertOk()->json();
        $this->assertSame(1, $report['approved']);
        $this->assertEqualsWithDelta(80.0, $report['total_refunded'], 0.001);
    }

    public function test_finance_officer_can_reject_a_refund_and_wallet_method_credits_the_passenger(): void
    {
        Queue::fake([DeliverPlatformNotification::class]);
        [$company, $finance, $rejectedBooking] = $this->cancelledBookingFixture('original');
        [, , $walletBooking, $passengerUser] = $this->cancelledBookingFixture('wallet', $company, $finance);

        Sanctum::actingAs($finance);
        $refunds = $this->getJson('/api/v1/refunds?status=pending')->assertOk()->json('data');
        $rejectId = collect($refunds)->firstWhere('code', $rejectedBooking->reference)['id'];
        $walletRefundId = collect($refunds)->firstWhere('code', $walletBooking->reference)['id'];

        $this->postJson("/api/v1/refunds/{$rejectId}/rejection", ['reason' => 'Fraudulent claim'])->assertOk()->assertJsonPath('status', 'rejected');

        $wallet = app(WalletService::class)->account($passengerUser);
        $this->assertSame(0.0, (float) $wallet->balance);
        $this->postJson("/api/v1/refunds/{$walletRefundId}/approval")->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame(80.0, (float) $wallet->refresh()->balance);
    }

    public function test_a_refund_can_no_longer_be_edited_through_the_generic_module_endpoint(): void
    {
        Queue::fake([DeliverPlatformNotification::class]);
        [, $finance, $booking] = $this->cancelledBookingFixture('original');
        Sanctum::actingAs($finance);
        $refundId = $this->getJson('/api/v1/refunds')->assertOk()->json('data.0.id');

        $this->patchJson("/api/v1/modules/refunds/records/{$refundId}", ['status' => 'approved'])->assertStatus(409);
    }

    /** @return array{Company, User, Booking, User} */
    private function cancelledBookingFixture(string $refundMethod, ?Company $company = null, ?User $finance = null): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company ??= Company::create(['name' => 'Refund Co', 'slug' => 'refund-co-'.str()->random(6), 'settings' => ['cancellation_policy' => [['minimum_hours' => 24, 'refund_percent' => 80], ['minimum_hours' => 0, 'refund_percent' => 0]]]]);
        if (! $finance) {
            $finance = User::factory()->create(['company_id' => $company->id, 'role' => 'finance_manager']);
            $finance->assignRole('finance_manager');
        }
        $passenger = User::factory()->create();
        $origin = Terminal::create(['name' => 'Harare', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Mutare', 'city' => 'Mutare', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Route', 'duration_minutes' => 240]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'RFD-'.str()->random(6), 'model' => 'Scania', 'seat_capacity' => 1]);
        $seat = Seat::create(['bus_id' => $bus->id, 'number' => '1A']);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(2), 'arrives_at' => now()->addDays(2)->addHours(4), 'base_fare' => 100, 'currency' => 'USD', 'status' => 'published']);
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKRFD'.strtoupper(str()->random(6)), 'company_id' => $company->id, 'trip_id' => $trip->id, 'user_id' => $passenger->id, 'contact_name' => 'Passenger', 'contact_phone' => '+263771234567', 'subtotal' => 100, 'total' => 100, 'currency' => 'USD', 'status' => 'confirmed']);
        $bookingPassenger = $booking->passengers()->create(['trip_id' => $trip->id, 'seat_id' => $seat->id, 'full_name' => 'Passenger', 'type' => 'adult', 'fare' => 100, 'status' => 'confirmed']);
        Ticket::create(['public_id' => Str::uuid(), 'ticket_number' => 'TKRFD'.strtoupper(str()->random(6)), 'booking_passenger_id' => $bookingPassenger->id, 'qr_token' => hash('sha256', Str::uuid())]);
        Payment::create(['booking_id' => $booking->id, 'provider' => 'demo', 'provider_reference' => 'DEMO-'.strtoupper(str()->random(10)), 'idempotency_key' => 'seed-payment-'.$booking->id, 'amount' => 100, 'currency' => 'USD', 'status' => 'paid', 'paid_at' => now()]);

        Sanctum::actingAs($passenger);
        $this->postJson("/api/v1/bookings/{$booking->id}/cancellations", ['reason' => 'Plans changed', 'refund_method' => $refundMethod])->assertCreated();

        return [$company, $finance, $booking->refresh(), $passenger];
    }
}
