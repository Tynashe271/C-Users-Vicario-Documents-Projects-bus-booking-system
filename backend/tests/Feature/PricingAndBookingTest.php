<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\FareRule;
use App\Models\Seat;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingAndBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_and_booking_apply_passenger_fares_services_coupon_tax_and_commission(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Operator', 'slug' => 'operator', 'settings' => ['tax_rate' => 10, 'commission_rate' => 5, 'booking_service_fee' => 2, 'optional_services' => [['code' => 'extra_bag', 'price' => 3]]]]);
        $origin = Terminal::create(['name' => 'Harare', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Mutare', 'city' => 'Mutare', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare-Mutare', 'duration_minutes' => 240]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'ABC123', 'model' => 'Scania', 'seat_capacity' => 2]);
        $seats = collect([Seat::create(['bus_id' => $bus->id, 'number' => '1A']), Seat::create(['bus_id' => $bus->id, 'number' => '1B'])]);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(2), 'arrives_at' => now()->addDays(2)->addHours(4), 'base_fare' => 100, 'currency' => 'USD', 'status' => 'published']);
        Coupon::create(['company_id' => $company->id, 'code' => 'SAVE10', 'name' => 'Ten percent off', 'status' => 'active', 'amount' => 10, 'currency' => 'USD', 'data' => ['discount_type' => 'percentage', 'usage_limit' => 10, 'used_count' => 0]]);
        $this->withToken($user->createToken('Passenger web')->plainTextToken);
        $quote = $this->postJson("/api/v1/trips/{$trip->id}/quote", ['passengers' => [['type' => 'adult'], ['type' => 'child']], 'optional_services' => ['extra_bag'], 'coupon_code' => 'SAVE10']);
        $quote->assertOk()->assertJsonPath('subtotal', 153)->assertJsonPath('discount', 15.3)->assertJsonPath('taxes', 13.77)->assertJsonPath('platform_fee', 6.89)->assertJsonPath('total', 160.36);
        $lock = $this->postJson("/api/v1/trips/{$trip->id}/seat-locks", ['seat_ids' => $seats->pluck('id')->all()])->assertCreated();

        $booking = $this->postJson("/api/v1/trips/{$trip->id}/bookings", ['lock_token' => $lock->json('token'), 'contact_name' => 'Test Passenger', 'contact_email' => 'contact@example.com', 'contact_phone' => '+263771234567', 'coupon_code' => 'SAVE10', 'optional_services' => ['extra_bag'], 'booking_terms_accepted' => true, 'booking_type' => 'family', 'passengers' => [['seat_id' => $seats[0]->id, 'full_name' => 'Adult Passenger', 'phone' => '+263771111111', 'email' => 'adult@example.com', 'type' => 'adult'], ['seat_id' => $seats[1]->id, 'full_name' => 'Child Passenger', 'phone' => '+263772222222', 'email' => 'guardian@example.com', 'type' => 'child']]]);

        $booking->assertCreated()->assertJsonPath('total', '160.36')->assertJsonPath('booking_type', 'family');
        $this->assertDatabaseHas('bookings', ['id' => $booking->json('id'), 'user_id' => $user->id]);
        $this->assertDatabaseHas('booking_passengers', ['trip_id' => $trip->id, 'seat_id' => $seats[0]->id]);
        $this->assertSame(1, Coupon::first()->data['used_count']);
    }

    public function test_unpaid_booking_expires_and_releases_its_seat_and_coupon(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Operator', 'slug' => 'operator-expiry', 'settings' => []]);
        $origin = Terminal::create(['name' => 'Harare', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Mutare', 'city' => 'Mutare', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare-Mutare', 'duration_minutes' => 240]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'EXP123', 'model' => 'Scania', 'seat_capacity' => 1]);
        $seat = Seat::create(['bus_id' => $bus->id, 'number' => '1A']);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(2), 'arrives_at' => now()->addDays(2)->addHours(4), 'base_fare' => 100, 'currency' => 'USD', 'status' => 'published']);
        $coupon = Coupon::create(['company_id' => $company->id, 'code' => 'ONCE', 'name' => 'One use', 'status' => 'active', 'amount' => 10, 'currency' => 'USD', 'data' => ['discount_type' => 'fixed', 'usage_limit' => 1, 'used_count' => 0]]);
        $this->actingAs($user);
        $lock = $this->postJson("/api/v1/trips/{$trip->id}/seat-locks", ['seat_ids' => [$seat->id]])->assertCreated();
        $booking = $this->postJson("/api/v1/trips/{$trip->id}/bookings", [
            'lock_token' => $lock->json('token'), 'contact_name' => 'Test Passenger', 'contact_email' => 'contact@example.com', 'contact_phone' => '+263771234567', 'coupon_code' => 'ONCE', 'booking_terms_accepted' => true,
            'passengers' => [['seat_id' => $seat->id, 'full_name' => 'Test Passenger', 'phone' => '+263771234567', 'email' => 'contact@example.com', 'type' => 'adult']],
        ])->assertCreated();

        $this->assertDatabaseHas('booking_passengers', ['booking_id' => $booking->json('id'), 'status' => 'held']);
        $this->travel(11)->minutes();
        app(BookingService::class)->releaseExpiredBookings();

        $this->assertDatabaseHas('bookings', ['id' => $booking->json('id'), 'status' => 'expired']);
        $this->assertDatabaseMissing('booking_passengers', ['booking_id' => $booking->json('id')]);
        $this->assertSame(0, $coupon->refresh()->data['used_count']);
        $this->postJson("/api/v1/trips/{$trip->id}/seat-locks", ['seat_ids' => [$seat->id]])->assertCreated();
    }

    public function test_coupon_assigned_to_another_user_is_rejected(): void
    {
        $company = Company::create(['name' => 'Operator', 'slug' => 'operator-private-coupon', 'settings' => []]);
        $origin = Terminal::create(['name' => 'Harare', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Mutare', 'city' => 'Mutare', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare-Mutare', 'duration_minutes' => 240]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'CPN123', 'model' => 'Scania', 'seat_capacity' => 1]);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(2), 'arrives_at' => now()->addDays(2)->addHours(4), 'base_fare' => 100, 'currency' => 'USD', 'status' => 'published']);
        Coupon::create(['company_id' => $company->id, 'user_id' => User::factory()->create()->id, 'code' => 'PRIVATE', 'name' => 'Private coupon', 'status' => 'active', 'amount' => 10, 'currency' => 'USD', 'data' => []]);

        $this->actingAs(User::factory()->create())->postJson("/api/v1/trips/{$trip->id}/quote", ['passengers' => [['type' => 'adult']], 'coupon_code' => 'PRIVATE'])
            ->assertUnprocessable()->assertJsonValidationErrors('coupon_code');
    }

    public function test_fare_rules_apply_only_when_weekend_peak_hour_and_lead_time_conditions_match(): void
    {
        $company = Company::create(['name' => 'Operator', 'slug' => 'operator-fare-rules']);
        $origin = Terminal::create(['name' => 'Harare', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Mutare', 'city' => 'Mutare', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare-Mutare', 'duration_minutes' => 240]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'FARE123', 'model' => 'Scania', 'seat_capacity' => 1]);
        $departure = now()->addDays(10)->next(Carbon::SATURDAY)->setTime(18, 0);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => $departure, 'arrives_at' => $departure->copy()->addHours(4), 'base_fare' => 100, 'currency' => 'USD', 'status' => 'published']);

        FareRule::create(['company_id' => $company->id, 'code' => 'WEEKEND', 'name' => 'Weekend surcharge', 'status' => 'active', 'amount' => 20, 'currency' => 'USD', 'data' => ['passenger_type' => 'adult', 'adjustment_type' => 'percentage', 'days_of_week' => [6, 7]]]);
        FareRule::create(['company_id' => $company->id, 'code' => 'MORNING-OFFPEAK', 'name' => 'Morning off-peak discount (should not match an 18:00 departure)', 'status' => 'active', 'amount' => -50, 'currency' => 'USD', 'data' => ['passenger_type' => 'adult', 'adjustment_type' => 'percentage', 'hour_range' => ['from' => '05:00', 'to' => '08:00']]]);
        FareRule::create(['company_id' => $company->id, 'code' => 'LAST-MINUTE', 'name' => 'Requires 30+ days notice (should not match a 10-day-out booking)', 'status' => 'active', 'amount' => -50, 'currency' => 'USD', 'data' => ['passenger_type' => 'adult', 'adjustment_type' => 'percentage', 'min_days_before_departure' => 30]]);

        $quote = $this->postJson("/api/v1/trips/{$trip->id}/quote", ['passengers' => [['type' => 'adult']]])->assertOk()->json();
        // Only the weekend surcharge applies: 100 * 1.20 = 120.
        $this->assertEqualsWithDelta(120.0, $quote['passenger_fares'][0], 0.001);
    }

    public function test_booking_conditions_must_be_accepted(): void
    {
        $company = Company::create(['name' => 'Terms Operator', 'slug' => 'terms-operator', 'settings' => []]);
        $origin = Terminal::create(['name' => 'Harare', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Mutare', 'city' => 'Mutare', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Terms Route', 'duration_minutes' => 240]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'TRM123', 'model' => 'Scania', 'seat_capacity' => 1]);
        $seat = Seat::create(['bus_id' => $bus->id, 'number' => '1A']);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(2), 'arrives_at' => now()->addDays(2)->addHours(4), 'base_fare' => 100, 'currency' => 'USD', 'status' => 'published']);
        $lock = $this->postJson("/api/v1/trips/{$trip->id}/seat-locks", ['seat_ids' => [$seat->id]])->assertCreated();

        $this->postJson("/api/v1/trips/{$trip->id}/bookings", [
            'lock_token' => $lock->json('token'), 'contact_name' => 'Test Passenger', 'contact_email' => 'contact@example.com', 'contact_phone' => '+263771234567',
            'passengers' => [['seat_id' => $seat->id, 'full_name' => 'Test Passenger', 'phone' => '+263771234567', 'email' => 'contact@example.com', 'type' => 'adult']],
        ])->assertUnprocessable()->assertJsonValidationErrors('booking_terms_accepted');
    }
}
