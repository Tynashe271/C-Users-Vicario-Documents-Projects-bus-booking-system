<?php

namespace Tests\Feature;

use App\Jobs\DeliverPlatformNotification;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Company;
use App\Models\NotificationRecord;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use App\Services\PassengerJourneyNotificationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class LiveTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_updates_location_and_passenger_can_share_privacy_safe_tracking(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [$company, $trip] = $this->tripFixture();
        $driver = User::factory()->create(['company_id' => $company->id, 'role' => 'driver']);
        $passenger = User::factory()->create(['role' => 'passenger']);
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKTRACK1', 'company_id' => $company->id, 'trip_id' => $trip->id, 'user_id' => $passenger->id, 'contact_name' => 'Passenger', 'contact_phone' => '+263770000000', 'subtotal' => 30, 'total' => 30, 'currency' => 'USD', 'status' => 'confirmed']);

        $this->actingAs($driver)->postJson("/api/v1/trips/{$trip->id}/locations", ['latitude' => -17.8252, 'longitude' => 31.0335, 'speed_kph' => 72, 'heading' => 210, 'accuracy_m' => 8, 'recorded_at' => now()->toIso8601String()])
            ->assertCreated()->assertJsonPath('trip_id', $trip->id)->assertJsonPath('location.speed_kph', 72);

        $share = $this->actingAs($passenger)->postJson("/api/v1/trips/{$trip->id}/tracking-links", ['booking_id' => $booking->id, 'privacy_precision' => 'approximate'])->assertCreated();
        $this->getJson('/api/v1/tracking/'.$share->json('token'))->assertOk()->assertJsonPath('operator', 'Road Star')->assertJsonPath('location.latitude', -17.825)->assertJsonPath('location.speed_kph', null);

        $manager = User::factory()->create(['company_id' => $company->id, 'role' => 'operations_manager']);
        $manager->assignRole('operations_manager');
        $this->actingAs($manager)->getJson("/api/v1/trips/{$trip->id}/location-history")->assertOk()->assertJsonCount(1, 'locations');
    }

    public function test_driver_cannot_update_another_company_trip(): void
    {
        [$company, $trip] = $this->tripFixture();
        $other = Company::create(['name' => 'Other Operator', 'slug' => 'other']);
        $driver = User::factory()->create(['company_id' => $other->id, 'role' => 'driver']);

        $this->actingAs($driver)->postJson("/api/v1/trips/{$trip->id}/locations", ['latitude' => -17.8, 'longitude' => 31.0, 'speed_kph' => 50, 'recorded_at' => now()->toIso8601String()])->assertNotFound();
    }

    public function test_operational_changes_and_departure_reminders_notify_passengers_once(): void
    {
        Queue::fake([DeliverPlatformNotification::class]);
        [$company, $trip] = $this->tripFixture();
        $passenger = User::factory()->create(['role' => 'passenger']);
        Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKNOTIFY1', 'company_id' => $company->id, 'trip_id' => $trip->id, 'user_id' => $passenger->id, 'contact_name' => 'Passenger', 'contact_email' => $passenger->email, 'contact_phone' => '+263770000000', 'subtotal' => 30, 'total' => 30, 'currency' => 'USD', 'status' => 'confirmed']);

        $trip->update(['status' => 'delayed']);
        $this->assertDatabaseHas('notifications', ['user_id' => $passenger->id, 'event_type' => 'trip_delay', 'channel' => 'in_app']);

        $trip->update(['departs_at' => now()->addDay(), 'arrives_at' => now()->addDay()->addHours(6), 'status' => 'published']);
        app(PassengerJourneyNotificationService::class)->departureReminders();
        app(PassengerJourneyNotificationService::class)->departureReminders();
        $this->assertSame(1, NotificationRecord::where('user_id', $passenger->id)->where('event_type', 'departure_reminder')->where('channel', 'in_app')->count());
        $this->assertDatabaseHas('notifications', ['user_id' => $passenger->id, 'event_type' => 'boarding_change', 'channel' => 'in_app']);
    }

    /** @return array{Company, Trip} */
    private function tripFixture(): array
    {
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star']);
        $origin = Terminal::create(['name' => 'Roadport', 'city' => 'Harare', 'country' => 'ZW', 'latitude' => -17.8252, 'longitude' => 31.0335]);
        $destination = Terminal::create(['name' => 'Intercity', 'city' => 'Bulawayo', 'country' => 'ZW', 'latitude' => -20.1500, 'longitude' => 28.5833]);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare to Bulawayo', 'duration_minutes' => 360]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'TRACK123', 'model' => 'Scania', 'seat_capacity' => 50]);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->subHour(), 'arrives_at' => now()->addHours(5), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'departed']);

        return [$company, $trip];
    }
}
