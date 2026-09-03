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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CancellationAndReschedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancellation_voids_ticket_and_calculates_policy_refund(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Operator', 'slug' => 'operator', 'settings' => ['cancellation_policy' => [['minimum_hours' => 24, 'refund_percent' => 80], ['minimum_hours' => 0, 'refund_percent' => 0]]]]);
        $origin = Terminal::create(['name' => 'Harare', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Mutare', 'city' => 'Mutare', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Route', 'duration_minutes' => 240]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'ABC123', 'model' => 'Scania', 'seat_capacity' => 1]);
        $seat = Seat::create(['bus_id' => $bus->id, 'number' => '1A']);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(2), 'arrives_at' => now()->addDays(2)->addHours(4), 'base_fare' => 100, 'currency' => 'USD', 'status' => 'published']);
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKTEST1', 'company_id' => $company->id, 'trip_id' => $trip->id, 'user_id' => $user->id, 'contact_name' => 'Passenger', 'contact_phone' => '+263771234567', 'subtotal' => 100, 'total' => 100, 'currency' => 'USD', 'status' => 'confirmed']);
        $passenger = $booking->passengers()->create(['trip_id' => $trip->id, 'seat_id' => $seat->id, 'full_name' => 'Passenger', 'type' => 'adult', 'fare' => 100]);
        $ticket = Ticket::create(['public_id' => Str::uuid(), 'ticket_number' => 'TKTEST1', 'booking_passenger_id' => $passenger->id, 'qr_token' => hash('sha256', 'ticket')]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/bookings/{$booking->id}/cancellation-quote")->assertOk()->assertJsonPath('cancellation_charge', 20)->assertJsonPath('refund_amount', 80)->assertJsonCount(2, 'rules');
        $this->postJson("/api/v1/bookings/{$booking->id}/cancellations", ['reason' => 'Plans changed', 'refund_method' => 'original'])->assertCreated()->assertJsonPath('refund_amount', 80)->assertJsonPath('booking_status', 'cancelled');
        $this->assertSame('void', $ticket->refresh()->status);
        $this->assertNull($passenger->refresh()->trip_id);
        $this->assertDatabaseHas('refunds', ['code' => 'BKTEST1', 'amount' => 80, 'status' => 'pending']);
        $this->postJson("/api/v1/trips/{$trip->id}/seat-locks", ['seat_ids' => [$seat->id]])->assertCreated();
    }

    public function test_rescheduling_checks_availability_calculates_difference_and_updates_ticket_after_payment(): void
    {
        Queue::fake([DeliverPlatformNotification::class]);
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Reschedule Operator', 'slug' => 'reschedule-operator', 'settings' => []]);
        $origin = Terminal::create(['name' => 'Harare', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Mutare', 'city' => 'Mutare', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Route', 'duration_minutes' => 240]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'RES123', 'model' => 'Scania', 'seat_capacity' => 2]);
        $oldSeat = Seat::create(['bus_id' => $bus->id, 'number' => '1A']);
        $newSeat = Seat::create(['bus_id' => $bus->id, 'number' => '1B']);
        $oldTrip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDay(), 'arrives_at' => now()->addDay()->addHours(4), 'base_fare' => 100, 'currency' => 'USD', 'status' => 'published']);
        $newTrip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(2), 'arrives_at' => now()->addDays(2)->addHours(4), 'base_fare' => 120, 'currency' => 'USD', 'status' => 'published']);
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKRESCHEDULE', 'company_id' => $company->id, 'trip_id' => $oldTrip->id, 'user_id' => $user->id, 'contact_name' => 'Passenger', 'contact_email' => $user->email, 'contact_phone' => '+263771234567', 'subtotal' => 100, 'total' => 100, 'currency' => 'USD', 'status' => 'confirmed']);
        $passenger = $booking->passengers()->create(['trip_id' => $oldTrip->id, 'seat_id' => $oldSeat->id, 'full_name' => 'Passenger', 'type' => 'adult', 'fare' => 100, 'status' => 'confirmed']);
        $ticket = Ticket::create(['public_id' => Str::uuid(), 'ticket_number' => 'TKRESCHEDULE', 'booking_passenger_id' => $passenger->id, 'qr_token' => hash('sha256', 'old-ticket')]);
        Payment::create(['booking_id' => $booking->id, 'provider' => 'demo', 'provider_reference' => 'OLDPAYMENT', 'idempotency_key' => 'old-reschedule-payment', 'amount' => 100, 'currency' => 'USD', 'status' => 'paid', 'paid_at' => now()]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/bookings/{$booking->id}/reschedule-options")->assertOk()->assertJsonPath('data.0.id', $newTrip->id)->assertJsonPath('data.0.available_seats', 2);
        $lock = $this->postJson("/api/v1/trips/{$newTrip->id}/seat-locks", ['seat_ids' => [$newSeat->id]])->assertCreated();
        $this->postJson("/api/v1/bookings/{$booking->id}/reschedules", ['trip_id' => $newTrip->id, 'lock_token' => $lock->json('token'), 'seats' => [['passenger_id' => $passenger->id, 'seat_id' => $newSeat->id]]])
            ->assertOk()->assertJsonPath('fare_difference', 20)->assertJsonPath('booking.status', 'pending_payment');

        $this->assertSame('pending_payment', $ticket->refresh()->status);
        $this->assertDatabaseHas('booking_passengers', ['id' => $passenger->id, 'trip_id' => $newTrip->id, 'seat_id' => $newSeat->id, 'fare' => 120]);
        $this->postJson("/api/v1/bookings/{$booking->id}/payments", ['provider' => 'demo', 'amount' => 20], ['Idempotency-Key' => 'reschedule-payment-0001'])->assertCreated()->assertJsonPath('status', 'paid');
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'confirmed']);
        $this->assertSame('active', $ticket->refresh()->status);
    }
}
