<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Bus;
use App\Models\Company;
use App\Models\PlatformResource;
use App\Models\Seat;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TripSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_passengers_can_discover_terminals_and_filter_trips_with_live_availability(): void
    {
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star', 'settings' => [
            'luggage_allowance' => 'Two bags up to 20 kg total',
            'refund_policy' => ['code' => 'flexible', 'label' => 'Flexible refunds'],
            'cancellation_policy' => [['minimum_hours' => 24, 'refund_percent' => 80]],
        ]]);
        $origin = Terminal::create(['name' => 'Roadport', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Intercity', 'city' => 'Bulawayo', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare to Bulawayo', 'duration_minutes' => 360]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'AAB1234', 'model' => 'Scania Touring', 'class' => 'luxury', 'seat_capacity' => 2, 'amenities' => ['wifi', 'charging_ports'], 'images' => ['https://example.com/bus.jpg']]);
        $availableSeat = Seat::create(['bus_id' => $bus->id, 'number' => '1A', 'type' => 'window']);
        $bookedSeat = Seat::create(['bus_id' => $bus->id, 'number' => '1B', 'type' => 'aisle']);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(2)->setTime(8, 0), 'arrives_at' => now()->addDays(2)->setTime(14, 0), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'available']);
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKSEARCH1', 'company_id' => $company->id, 'trip_id' => $trip->id, 'contact_name' => 'Traveller', 'contact_phone' => '+263770000000', 'subtotal' => 30, 'total' => 30, 'currency' => 'USD']);
        BookingPassenger::create(['booking_id' => $booking->id, 'trip_id' => $trip->id, 'seat_id' => $bookedSeat->id, 'full_name' => 'Booked Traveller', 'type' => 'adult', 'fare' => 30]);
        (new PlatformResource)->useModule('reviews')->newQuery()->create(['company_id' => $company->id, 'code' => 'review-1', 'name' => 'Excellent journey', 'status' => 'active', 'amount' => 4.7]);
        (new PlatformResource)->useModule('trip_stops')->newQuery()->create(['company_id' => $company->id, 'code' => 'trip-stop-1', 'name' => 'Gweru', 'status' => 'active', 'starts_at' => $trip->departs_at->copy()->addHours(3), 'data' => ['trip_id' => $trip->id, 'terminal' => 'Gweru Intercity']]);

        $this->getJson('/api/v1/terminals?search=Hara')->assertOk()->assertJsonPath('data.0.id', $origin->id);

        $this->getJson('/api/v1/trips?'.http_build_query(['origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'date' => $trip->departs_at->toDateString(), 'company_id' => $company->id, 'bus_class' => 'luxury', 'amenities' => ['wifi'], 'minimum_seats' => 1, 'max_price' => 35, 'departure_from' => '07:00', 'departure_to' => '09:00', 'arrival_to' => '15:00', 'max_duration' => 400, 'refund_policy' => 'flexible', 'min_rating' => 4.5, 'sort' => 'rating_desc']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $trip->id)
            ->assertJsonPath('data.0.available_seats', 1)
            ->assertJsonPath('data.0.operator_rating', 4.7)
            ->assertJsonPath('data.0.refund_policy.code', 'flexible')
            ->assertJsonPath('data.0.bus.seats.0.id', $availableSeat->id)
            ->assertJsonPath('data.0.bus.seats.0.availability', 'available')
            ->assertJsonPath('data.0.bus.seats.0.position', 'window')
            ->assertJsonPath('data.0.bus.seats.1.availability', 'occupied')
            ->assertJsonPath('data.0.bus.seats.1.position', 'aisle');

        $this->getJson("/api/v1/trips/{$trip->id}")
            ->assertOk()
            ->assertJsonPath('bus.images.0', 'https://example.com/bus.jpg')
            ->assertJsonPath('luggage_allowance', 'Two bags up to 20 kg total')
            ->assertJsonPath('intermediate_stops.0.name', 'Gweru');
    }

    public function test_search_returns_matching_trips_from_different_bus_companies(): void
    {
        $origin = Terminal::create(['name' => 'Roadport', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Intercity', 'city' => 'Bulawayo', 'country' => 'ZW']);
        $date = now()->addDays(3);

        foreach (['Road Star', 'City Link'] as $index => $name) {
            $company = Company::create(['name' => $name, 'slug' => str($name)->slug()]);
            $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => "{$name} route", 'duration_minutes' => 360]);
            $bus = Bus::create(['company_id' => $company->id, 'registration_number' => "BUS{$index}123", 'model' => 'Scania', 'class' => 'standard', 'seat_capacity' => 1]);
            Seat::create(['bus_id' => $bus->id, 'number' => '1A', 'type' => 'window']);
            Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => $date->copy()->setTime(8 + $index, 0), 'arrives_at' => $date->copy()->setTime(14 + $index, 0), 'base_fare' => 30 + $index, 'currency' => 'USD', 'status' => 'available']);
        }

        $this->getJson('/api/v1/trips?'.http_build_query(['origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'date' => $date->toDateString()]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.company.name', 'Road Star')
            ->assertJsonPath('data.1.company.name', 'City Link');
    }
}
