<?php

namespace Tests\Feature;

use App\Events\TripSeatsUpdated;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Services\TripOccupancyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_trip_search_results_are_cached_briefly_but_seat_availability_always_stays_live(): void
    {
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-perf']);
        $origin = Terminal::create(['name' => 'Roadport', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Intercity', 'city' => 'Bulawayo', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare-Bulawayo', 'duration_minutes' => 360]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'PERF123', 'model' => 'Scania', 'seat_capacity' => 2]);
        $seat1 = Seat::create(['bus_id' => $bus->id, 'number' => '1A']);
        Seat::create(['bus_id' => $bus->id, 'number' => '1B']);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(2)->setTime(8, 0), 'arrives_at' => now()->addDays(2)->setTime(14, 0), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'available']);
        $query = ['origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'date' => $trip->departs_at->toDateString()];

        $first = $this->getJson('/api/v1/trips?'.http_build_query($query))->assertOk()->json();
        $this->assertCount(1, $first['data']);
        $this->assertSame(2, $first['data'][0]['available_seats']);

        // A seat gets held between requests — a live "someone is mid-booking" lock.
        SeatLock::create(['token' => Str::uuid(), 'trip_id' => $trip->id, 'seat_id' => $seat1->id, 'expires_at' => now()->addMinutes(5)]);
        // A second trip that would also match this exact search, created after the first request.
        Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(2)->setTime(9, 0), 'arrives_at' => now()->addDays(2)->setTime(15, 0), 'base_fare' => 25, 'currency' => 'USD', 'status' => 'available']);

        $second = $this->getJson('/api/v1/trips?'.http_build_query($query))->assertOk()->json();
        // The cached trip list still only shows the original trip — the new one hasn't appeared yet.
        $this->assertCount(1, $second['data']);
        $this->assertSame($trip->id, $second['data'][0]['id']);
        // But seat availability for that cached trip is never cached — it reflects the new hold immediately.
        $this->assertSame(1, $second['data'][0]['available_seats']);
    }

    public function test_occupancy_sync_broadcasts_a_live_seat_update_over_the_trips_channel(): void
    {
        Event::fake([TripSeatsUpdated::class]);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-broadcast']);
        $trip = $this->tripWithCapacity($company, 10);
        $this->confirmPassengers($trip, 9); // 90% full -> crosses the almost_full threshold

        app(TripOccupancyService::class)->sync($trip->fresh());

        Event::assertDispatched(TripSeatsUpdated::class, fn (TripSeatsUpdated $event): bool => $event->tripId === $trip->id && $event->availableSeats === 1 && $event->status === 'almost_full');
        $this->assertDatabaseHas('trips', ['id' => $trip->id, 'status' => 'almost_full']);
    }

    public function test_occupancy_sync_does_not_broadcast_for_a_trip_outside_the_booking_open_lifecycle(): void
    {
        Event::fake([TripSeatsUpdated::class]);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-broadcast-closed']);
        $trip = $this->tripWithCapacity($company, 10, 'departed');

        app(TripOccupancyService::class)->sync($trip);

        Event::assertNotDispatched(TripSeatsUpdated::class);
    }

    public function test_hot_lookup_columns_on_bookings_payments_trips_and_seat_locks_are_indexed(): void
    {
        $bookingColumns = collect(Schema::getIndexes('bookings'))->pluck('columns');
        $this->assertTrue($bookingColumns->contains(['company_id', 'status']));
        $this->assertTrue($bookingColumns->contains(['trip_id']));
        $this->assertTrue($bookingColumns->contains(['status', 'payable_until']));
        $this->assertTrue(collect(Schema::getIndexes('payments'))->pluck('columns')->contains(['booking_id', 'status']));
        $this->assertTrue(collect(Schema::getIndexes('trips'))->pluck('columns')->contains(['company_id']));
        $this->assertTrue(collect(Schema::getIndexes('seat_locks'))->pluck('columns')->contains(['expires_at']));
    }

    private function tripWithCapacity(Company $company, int $capacity, string $status = 'available'): Trip
    {
        $origin = Terminal::create(['name' => 'A '.Str::random(4), 'city' => 'A', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'B '.Str::random(4), 'city' => 'B', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Route '.Str::random(4), 'duration_minutes' => 240]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'OCC-'.Str::random(6), 'model' => 'Scania', 'seat_capacity' => $capacity]);
        for ($i = 1; $i <= $capacity; $i++) {
            Seat::create(['bus_id' => $bus->id, 'number' => "S{$i}"]);
        }

        return Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(2), 'arrives_at' => now()->addDays(2)->addHours(4), 'base_fare' => 30, 'currency' => 'USD', 'status' => $status]);
    }

    private function confirmPassengers(Trip $trip, int $count): void
    {
        $seats = Seat::where('bus_id', $trip->bus_id)->limit($count)->get();
        foreach ($seats as $seat) {
            $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKOCC'.$seat->id, 'company_id' => $trip->company_id, 'trip_id' => $trip->id, 'contact_name' => 'P', 'contact_phone' => '+263770000000', 'subtotal' => 30, 'total' => 30, 'currency' => 'USD', 'status' => 'confirmed']);
            BookingPassenger::create(['booking_id' => $booking->id, 'trip_id' => $trip->id, 'seat_id' => $seat->id, 'full_name' => 'P', 'type' => 'adult', 'fare' => 30, 'status' => 'confirmed']);
        }
    }
}
