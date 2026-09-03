<?php

namespace Tests\Feature;

use App\Jobs\DeliverPlatformNotification;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Seat;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentAndTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_owner_can_complete_a_demo_payment(): void
    {
        Queue::fake([DeliverPlatformNotification::class]);
        [$user, $booking, $passenger] = $this->paymentFixture();

        $response = $this->actingAs($user)->postJson("/api/v1/bookings/{$booking->id}/payments", [
            'provider' => 'demo',
            'amount' => 100,
            'context' => ['channel' => 'passenger_web'],
        ], ['Idempotency-Key' => 'payment-attempt-0001']);

        $response->assertCreated()
            ->assertJsonPath('provider', 'demo')
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('provider_payload.instructions', 'Demo payment completed successfully.');
        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'idempotency_key' => 'payment-attempt-0001',
            'provider' => 'demo',
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'confirmed']);
        $this->assertDatabaseHas('tickets', ['booking_passenger_id' => $passenger->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'event_type' => 'ticket_issued', 'channel' => 'email']);
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'event_type' => 'ticket_issued', 'channel' => 'sms']);
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'event_type' => 'payment_confirmation', 'channel' => 'in_app']);
        Queue::assertPushed(DeliverPlatformNotification::class, 3);
    }

    public function test_signed_paid_webhook_confirms_booking_and_issues_ticket(): void
    {
        Queue::fake([DeliverPlatformNotification::class]);
        [, $booking, $passenger] = $this->paymentFixture();
        config(['payments.providers.paynow.webhook_secret' => 'test-webhook-secret']);
        Payment::create([
            'booking_id' => $booking->id,
            'provider' => 'paynow',
            'provider_reference' => 'PAYNOW-123',
            'idempotency_key' => 'payment-attempt-0001',
            'amount' => 100,
            'currency' => 'USD',
            'status' => 'pending',
        ]);
        $payload = [
            'event_id' => 'event-123',
            'booking_reference' => $booking->reference,
            'provider_reference' => 'PAYNOW-123',
            'status' => 'paid',
            'amount' => 100,
            'currency' => 'USD',
        ];
        $content = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $content, 'test-webhook-secret');

        $response = $this->call('POST', '/api/v1/payments/webhooks/paynow', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => $signature,
        ], $content);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertDatabaseHas('payments', ['provider_reference' => 'PAYNOW-123', 'status' => 'paid']);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'confirmed']);
        $this->assertDatabaseHas('tickets', ['booking_passenger_id' => $passenger->id]);
    }

    public function test_guest_can_pay_without_creating_an_account(): void
    {
        Queue::fake([DeliverPlatformNotification::class]);
        [, $booking, $passenger] = $this->paymentFixture();
        $booking->update(['user_id' => null]);

        $response = $this->postJson("/api/v1/guest/bookings/{$booking->public_id}/payments", [
            'provider' => 'demo',
            'amount' => 100,
            'context' => ['channel' => 'guest_web'],
        ], ['Idempotency-Key' => 'guest-payment-attempt-0001']);

        $response->assertCreated()->assertJsonPath('status', 'paid');
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'user_id' => null, 'status' => 'confirmed']);
        $this->assertDatabaseHas('tickets', ['booking_passenger_id' => $passenger->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => null, 'event_type' => 'ticket_issued', 'channel' => 'email']);
    }

    public function test_permanent_payment_failure_releases_held_seat(): void
    {
        [, $booking, $passenger] = $this->paymentFixture();
        $passenger->update(['status' => 'held']);
        config(['payments.providers.paynow.webhook_secret' => 'test-webhook-secret']);
        Payment::create(['booking_id' => $booking->id, 'provider' => 'paynow', 'provider_reference' => 'PAYNOW-FAILED', 'idempotency_key' => 'failed-payment-0001', 'amount' => 100, 'currency' => 'USD', 'status' => 'pending']);
        $payload = ['event_id' => 'event-failed', 'booking_reference' => $booking->reference, 'provider_reference' => 'PAYNOW-FAILED', 'status' => 'failed', 'failure_is_permanent' => true, 'amount' => 100, 'currency' => 'USD'];
        $content = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/payments/webhooks/paynow', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $content, 'test-webhook-secret'),
        ], $content)->assertOk();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'payment_failed']);
        $this->assertDatabaseMissing('booking_passengers', ['id' => $passenger->id]);
        $this->assertDatabaseHas('payments', ['provider_reference' => 'PAYNOW-FAILED', 'status' => 'failed']);
    }

    public function test_idempotency_key_cannot_be_reused_for_another_booking(): void
    {
        [$user, $booking] = $this->paymentFixture();
        Payment::create(['booking_id' => $booking->id, 'provider' => 'demo', 'provider_reference' => 'FIRST', 'idempotency_key' => 'shared-payment-key', 'amount' => 100, 'currency' => 'USD', 'status' => 'paid']);
        $otherBooking = $booking->replicate()->fill(['public_id' => Str::uuid(), 'reference' => 'BKPAYMENT2']);
        $otherBooking->save();

        $this->actingAs($user)->postJson("/api/v1/bookings/{$otherBooking->id}/payments", ['provider' => 'demo', 'amount' => 100], ['Idempotency-Key' => 'shared-payment-key'])
            ->assertConflict();
        $this->assertSame(1, Payment::where('idempotency_key', 'shared-payment-key')->count());
    }

    public function test_passenger_can_list_booking_history_and_download_paid_receipt(): void
    {
        [$user, $booking] = $this->paymentFixture();
        $booking->update(['status' => 'confirmed']);
        Payment::create(['booking_id' => $booking->id, 'provider' => 'demo', 'provider_reference' => 'RECEIPT', 'idempotency_key' => 'receipt-payment-key', 'amount' => 100, 'currency' => 'USD', 'status' => 'paid', 'paid_at' => now()]);

        $this->actingAs($user)->getJson('/api/v1/bookings?category=upcoming')
            ->assertOk()->assertJsonPath('data.0.id', $booking->id)->assertJsonPath('data.0.payments.0.status', 'paid');
        $this->actingAs($user)->get("/api/v1/bookings/{$booking->id}/receipt")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_passenger_can_pay_from_wallet_without_duplicate_debit(): void
    {
        Queue::fake([DeliverPlatformNotification::class]);
        [$user, $booking] = $this->paymentFixture();
        $wallet = Wallet::create(['user_id' => $user->id, 'code' => 'passenger:USD', 'name' => 'Passenger wallet', 'status' => 'active', 'wallet_type' => 'passenger', 'currency' => 'USD', 'balance' => 150, 'available_balance' => 150]);
        $payload = ['provider' => 'passenger_wallet', 'amount' => 100];

        $this->actingAs($user)->postJson("/api/v1/bookings/{$booking->id}/payments", $payload, ['Idempotency-Key' => 'wallet-payment-0001'])
            ->assertCreated()->assertJsonPath('status', 'paid');
        $this->actingAs($user)->postJson("/api/v1/bookings/{$booking->id}/payments", $payload, ['Idempotency-Key' => 'wallet-payment-0001'])
            ->assertOk()->assertJsonPath('status', 'paid');

        $this->assertSame('50.00', $wallet->refresh()->available_balance);
        $this->assertSame(1, WalletTransaction::where('wallet_id', $wallet->id)->where('transaction_type', 'booking_payment')->count());
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'confirmed']);
    }

    public function test_guest_can_download_a_confirmed_ticket_with_a_valid_signed_link(): void
    {
        [, $booking, $passenger] = $this->paymentFixture();
        $booking->update(['user_id' => null, 'status' => 'confirmed']);
        $ticket = $passenger->ticket()->create([
            'public_id' => Str::uuid(),
            'ticket_number' => 'TKGUEST001',
            'qr_token' => hash('sha256', 'guest-ticket'),
        ]);
        $url = URL::temporarySignedRoute('guest.tickets.pdf', now()->addHour(), [
            'booking' => $booking->public_id,
            'ticket' => $ticket->public_id,
        ]);

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get("/api/v1/guest/bookings/{$booking->public_id}/tickets/{$ticket->public_id}")->assertForbidden();
    }

    /** @return array{User, Booking, BookingPassenger} */
    private function paymentFixture(): array
    {
        $company = Company::create(['name' => 'Payment Operator', 'slug' => 'payment-operator', 'status' => 'active', 'settings' => []]);
        $user = User::factory()->create();
        $origin = Terminal::create(['name' => 'Harare', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Bulawayo', 'city' => 'Bulawayo', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare-Bulawayo', 'duration_minutes' => 360]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'PAY123', 'model' => 'Scania', 'seat_capacity' => 1]);
        $seat = Seat::create(['bus_id' => $bus->id, 'number' => '1A']);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDay(), 'arrives_at' => now()->addDay()->addHours(6), 'base_fare' => 100, 'currency' => 'USD', 'status' => 'published']);
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKPAYMENT1', 'company_id' => $company->id, 'trip_id' => $trip->id, 'user_id' => $user->id, 'contact_name' => 'Paying Passenger', 'contact_email' => $user->email, 'contact_phone' => '+263771234567', 'subtotal' => 100, 'total' => 100, 'currency' => 'USD', 'status' => 'pending_payment', 'payable_until' => now()->addMinutes(20)]);
        $passenger = BookingPassenger::create(['booking_id' => $booking->id, 'trip_id' => $trip->id, 'seat_id' => $seat->id, 'full_name' => 'Paying Passenger', 'type' => 'adult', 'fare' => 100]);

        return [$user, $booking, $passenger];
    }
}
