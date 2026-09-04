<?php

namespace Tests\Feature;

use App\Jobs\DeliverPlatformNotification;
use App\Jobs\DeliverWebhookNotification;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Bus;
use App\Models\Commission;
use App\Models\Company;
use App\Models\PlatformResource;
use App\Models\Seat;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WebhookDispatcher;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_company_owner_can_manage_webhook_subscriptions_and_the_secret_is_only_ever_shown_once(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-webhooks']);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_owner']);
        $owner->assignRole('company_owner');
        Sanctum::actingAs($owner);

        $created = $this->postJson('/api/v1/admin/webhooks', ['name' => 'Partner ERP', 'url' => 'https://partner.example.test/hooks', 'events' => ['booking.confirmed']])
            ->assertCreated()->json();
        $this->assertArrayHasKey('secret', $created);
        $this->assertArrayNotHasKey('secret', $created['subscription']['data']);
        $webhookId = $created['subscription']['id'];

        // Neither listing nor showing it again ever re-exposes the secret.
        $this->getJson('/api/v1/admin/webhooks')->assertOk()->assertJsonMissingPath('data.0.data.secret');
        $this->getJson("/api/v1/admin/webhooks/{$webhookId}")->assertOk()->assertJsonMissingPath('data.secret');

        // An unrecognised event name is rejected outright.
        $this->postJson('/api/v1/admin/webhooks', ['name' => 'Bad', 'url' => 'https://partner.example.test/x', 'events' => ['not.a.real.event']])->assertUnprocessable();

        $this->patchJson("/api/v1/admin/webhooks/{$webhookId}", ['status' => 'paused'])->assertOk()->assertJsonPath('status', 'paused');

        $rotated = $this->postJson("/api/v1/admin/webhooks/{$webhookId}/rotation")->assertOk()->json();
        $this->assertNotSame($created['secret'], $rotated['secret']);
        $this->assertArrayNotHasKey('secret', $rotated['subscription']['data']);

        $this->deleteJson("/api/v1/admin/webhooks/{$webhookId}")->assertStatus(204);
        $this->getJson("/api/v1/admin/webhooks/{$webhookId}")->assertNotFound();
    }

    public function test_dispatching_an_event_only_queues_deliveries_for_active_subscriptions_that_want_it(): void
    {
        Queue::fake([DeliverWebhookNotification::class]);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-dispatch']);
        $wanted = $this->subscriptionFor($company, ['booking.confirmed'], 'active');
        $this->subscriptionFor($company, ['settlement.paid'], 'active'); // wrong event
        $this->subscriptionFor($company, ['booking.confirmed'], 'paused'); // right event, inactive
        $other = Company::create(['name' => 'Other', 'slug' => 'road-star-dispatch-other']);
        $this->subscriptionFor($other, ['booking.confirmed'], 'active'); // another company entirely

        app(WebhookDispatcher::class)->dispatch('booking.confirmed', ['booking_id' => 1], $company->id);

        Queue::assertPushed(DeliverWebhookNotification::class, 1);
        $this->assertDatabaseCount('webhook_deliveries', 1);
        $log = (new PlatformResource)->useModule('webhook_deliveries')->newQuery()->first();
        $this->assertSame($wanted->id, (int) data_get($log->data, 'subscription_id'));

        // A company with no company_id (e.g. a system-triggered event) is a safe no-op, not a crash.
        app(WebhookDispatcher::class)->dispatch('booking.confirmed', ['booking_id' => 2], null);
        Queue::assertPushed(DeliverWebhookNotification::class, 1);
    }

    public function test_delivering_a_webhook_signs_the_body_with_the_subscriptions_secret_and_marks_it_delivered(): void
    {
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-deliver']);
        $secret = 'whsec_test_secret';
        $subscription = $this->subscriptionFor($company, ['booking.confirmed'], 'active', $secret);
        $delivery = (new PlatformResource)->useModule('webhook_deliveries');
        $delivery->fill(['company_id' => $company->id, 'code' => 'whd:'.Str::uuid(), 'name' => 'test', 'status' => 'pending', 'data' => ['subscription_id' => $subscription->id, 'event' => 'booking.confirmed', 'payload' => ['booking_id' => 99]]])->save();

        Http::fake(['partner.example.test/*' => Http::response(['ok' => true], 200)]);
        (new DeliverWebhookNotification($delivery->id))->handle();

        Http::assertSent(function ($request) use ($secret) {
            $expectedSignature = hash_hmac('sha256', $request->body(), $secret);

            return $request->url() === 'https://partner.example.test/hooks'
                && $request->hasHeader('X-Webhook-Signature', $expectedSignature)
                && $request->hasHeader('X-Webhook-Event', 'booking.confirmed')
                && $request['event'] === 'booking.confirmed'
                && $request['data']['booking_id'] === 99;
        });
        $this->assertDatabaseHas('webhook_deliveries', ['id' => $delivery->id, 'status' => 'delivered']);
    }

    public function test_a_delivery_that_exhausts_every_retry_is_marked_exhausted_not_retried_forever(): void
    {
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-exhaust']);
        $subscription = $this->subscriptionFor($company, ['booking.confirmed'], 'active');
        $delivery = (new PlatformResource)->useModule('webhook_deliveries');
        $delivery->fill(['company_id' => $company->id, 'code' => 'whd:'.Str::uuid(), 'name' => 'test', 'status' => 'pending', 'data' => ['subscription_id' => $subscription->id, 'event' => 'booking.confirmed', 'payload' => []]])->save();

        Http::fake(['partner.example.test/*' => Http::response(['error' => 'down'], 500)]);
        $job = new DeliverWebhookNotification($delivery->id);
        try {
            $job->handle();
            $this->fail('Expected the 500 response to throw.');
        } catch (\Throwable $exception) {
            // Simulates what the queue worker does once tries are exhausted.
            $job->failed($exception);
        }

        $this->assertDatabaseHas('webhook_deliveries', ['id' => $delivery->id, 'status' => 'exhausted']);
    }

    public function test_confirming_a_paid_booking_dispatches_a_webhook_delivery_to_a_subscribed_partner(): void
    {
        Queue::fake([DeliverPlatformNotification::class, DeliverWebhookNotification::class]);
        $company = Company::create(['name' => 'Payment Operator', 'slug' => 'payment-operator-webhooks', 'status' => 'active']);
        $this->subscriptionFor($company, ['booking.confirmed'], 'active');
        $user = User::factory()->create();
        $origin = Terminal::create(['name' => 'Harare', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Bulawayo', 'city' => 'Bulawayo', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare-Bulawayo', 'duration_minutes' => 360]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'WHK123', 'model' => 'Scania', 'seat_capacity' => 1]);
        $seat = Seat::create(['bus_id' => $bus->id, 'number' => '1A']);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDay(), 'arrives_at' => now()->addDay()->addHours(6), 'base_fare' => 100, 'currency' => 'USD', 'status' => 'published']);
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKWEBHOOK1', 'company_id' => $company->id, 'trip_id' => $trip->id, 'user_id' => $user->id, 'contact_name' => 'Paying Passenger', 'contact_email' => $user->email, 'contact_phone' => '+263771234567', 'subtotal' => 100, 'total' => 100, 'currency' => 'USD', 'status' => 'pending_payment', 'payable_until' => now()->addMinutes(20)]);
        BookingPassenger::create(['booking_id' => $booking->id, 'trip_id' => $trip->id, 'seat_id' => $seat->id, 'full_name' => 'Paying Passenger', 'type' => 'adult', 'fare' => 100]);

        $this->actingAs($user)->postJson("/api/v1/bookings/{$booking->id}/payments", ['provider' => 'demo', 'amount' => 100, 'context' => ['channel' => 'passenger_web']], ['Idempotency-Key' => 'webhook-payment-0001'])->assertCreated();

        Queue::assertPushed(DeliverWebhookNotification::class, 1);
    }

    public function test_a_paid_settlement_dispatches_a_settlement_paid_webhook(): void
    {
        Queue::fake([DeliverWebhookNotification::class]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-settlement-webhook']);
        $this->subscriptionFor($company, ['settlement.paid'], 'active');
        $creator = User::factory()->create(['company_id' => $company->id, 'role' => 'finance_manager']);
        $creator->assignRole('finance_manager');
        $approver = User::factory()->create(['company_id' => $company->id, 'role' => 'finance_manager']);
        $approver->assignRole('finance_manager');
        $payer = User::factory()->create(['company_id' => $company->id, 'role' => 'finance_manager']);
        $payer->assignRole('finance_manager');

        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $this->routeFor($company)->id, 'bus_id' => $this->busFor($company)->id, 'departs_at' => now()->addDay(), 'arrives_at' => now()->addDay()->addHours(4), 'base_fare' => 100, 'currency' => 'USD', 'status' => 'completed']);
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKWHS1', 'company_id' => $company->id, 'trip_id' => $trip->id, 'contact_name' => 'P', 'contact_phone' => '+263770000000', 'subtotal' => 100, 'total' => 100, 'currency' => 'USD', 'status' => 'confirmed']);
        Commission::create(['company_id' => $company->id, 'booking_id' => $booking->id, 'code' => 'COM-WHS1', 'name' => 'Allocation', 'status' => 'available', 'amount' => 10, 'currency' => 'USD', 'gross_amount' => 100, 'platform_amount' => 10, 'agent_amount' => 0, 'operator_amount' => 90, 'available_at' => now()]);
        Wallet::create(['company_id' => $company->id, 'code' => 'operator:USD', 'name' => 'Operator wallet', 'wallet_type' => 'operator', 'status' => 'active', 'currency' => 'USD', 'balance' => 90, 'held_balance' => 90, 'available_balance' => 0]);

        Sanctum::actingAs($creator);
        $settlementId = $this->postJson('/api/v1/finance/settlements', ['period_start' => today()->toDateString(), 'period_end' => today()->toDateString(), 'currency' => 'USD'])->assertCreated()->json('id');
        Sanctum::actingAs($approver);
        $this->postJson("/api/v1/finance/settlements/{$settlementId}/approve")->assertOk();
        Sanctum::actingAs($payer);
        $this->postJson("/api/v1/finance/settlements/{$settlementId}/pay", ['payment_reference' => 'BANK-1'])->assertOk();

        Queue::assertPushed(DeliverWebhookNotification::class, 1);
    }

    public function test_api_key_usage_is_recorded_per_request_and_reportable_by_date_range(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-usage']);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_administrator']);
        $owner->assignRole('company_administrator');
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKUSAGE1', 'company_id' => $company->id, 'trip_id' => $this->tripFor($company)->id, 'contact_name' => 'P', 'contact_phone' => '+263770000000', 'subtotal' => 30, 'total' => 30, 'currency' => 'USD', 'status' => 'confirmed']);

        Sanctum::actingAs($owner);
        $created = $this->postJson('/api/v1/admin/api-clients', ['name' => 'Usage Integration', 'role' => 'company_administrator', 'abilities' => ['bookings.read']])->assertCreated()->json();
        $apiKey = $created['api_key'];
        $apiClientId = $created['client']['id'];

        $this->withHeaders(['X-Api-Key' => $apiKey])->getJson("/api/v1/partner/bookings/{$booking->id}")->assertOk();
        $this->withHeaders(['X-Api-Key' => $apiKey])->getJson("/api/v1/partner/bookings/{$booking->id}")->assertOk();
        $this->withHeaders(['X-Api-Key' => $apiKey])->getJson("/api/v1/partner/bookings/{$booking->id}")->assertOk();

        Sanctum::actingAs($owner);
        $usage = $this->getJson("/api/v1/admin/api-clients/{$apiClientId}/usage")->assertOk()->json();
        $this->assertSame(3, $usage['total_requests']);
        $this->assertSame(3, $usage['daily'][now()->toDateString()]);
    }

    /** @param list<string> $events */
    private function subscriptionFor(Company $company, array $events, string $status, string $secret = 'whsec_default'): PlatformResource
    {
        $subscription = (new PlatformResource)->useModule('webhook_subscriptions');
        $subscription->fill(['company_id' => $company->id, 'code' => 'wh-'.Str::random(10), 'name' => 'Sub '.Str::random(4), 'status' => $status, 'data' => ['url' => 'https://partner.example.test/hooks', 'events' => $events, 'secret' => $secret]])->save();

        return $subscription;
    }

    private function tripFor(Company $company): Trip
    {
        $route = $this->routeFor($company);
        $bus = $this->busFor($company);

        return Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(2), 'arrives_at' => now()->addDays(2)->addHours(4), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'published']);
    }

    private function routeFor(Company $company): TransportRoute
    {
        $origin = Terminal::create(['name' => 'A '.Str::random(4), 'city' => 'A', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'B '.Str::random(4), 'city' => 'B', 'country' => 'ZW']);

        return TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Route '.Str::random(4), 'duration_minutes' => 240]);
    }

    private function busFor(Company $company): Bus
    {
        return Bus::create(['company_id' => $company->id, 'registration_number' => 'WH-'.Str::random(6), 'model' => 'Scania', 'seat_capacity' => 1]);
    }
}
