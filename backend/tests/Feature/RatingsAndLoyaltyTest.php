<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Bus;
use App\Models\Company;
use App\Models\LoyaltyAccount;
use App\Models\PlatformResource;
use App\Models\Seat;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RatingsAndLoyaltyTest extends TestCase
{
    use RefreshDatabase;

    public function test_passenger_can_rate_every_category_after_travelling(): void
    {
        [$user, $booking] = $this->completedBooking(120);

        $this->actingAs($user)->postJson("/api/v1/bookings/{$booking->id}/review", [
            'cleanliness' => 5, 'comfort' => 4, 'punctuality' => 3, 'driver_professionalism' => 5,
            'customer_service' => 4, 'overall_experience' => 5, 'comment' => 'A comfortable journey.',
        ])->assertCreated()->assertJsonPath('amount', '4.33');
        $this->assertDatabaseHas('reviews', ['booking_id' => $booking->id, 'user_id' => $user->id, 'cleanliness' => 5, 'overall_experience' => 5]);
    }

    public function test_review_is_rejected_before_travel_and_for_another_passenger(): void
    {
        [$user, $booking] = $this->completedBooking();
        $booking->trip->update(['arrives_at' => now()->addHour()]);
        $ratings = ['cleanliness' => 5, 'comfort' => 5, 'punctuality' => 5, 'driver_professionalism' => 5, 'customer_service' => 5, 'overall_experience' => 5];

        $this->actingAs($user)->postJson("/api/v1/bookings/{$booking->id}/review", $ratings)->assertConflict();
        $this->actingAs(User::factory()->create())->postJson("/api/v1/bookings/{$booking->id}/review", $ratings)->assertNotFound();
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_completed_trip_awards_points_once_and_updates_membership(): void
    {
        [$user, $booking] = $this->completedBooking(600);

        $this->actingAs($user)->getJson('/api/v1/loyalty')->assertOk()->assertJsonPath('account.points_balance', 600)->assertJsonPath('account.membership_level', 'Silver');
        $this->getJson('/api/v1/loyalty')->assertOk()->assertJsonPath('account.points_balance', 600);
        $this->assertDatabaseHas('loyalty_transactions', ['booking_id' => $booking->id, 'points' => 600, 'transaction_type' => 'trip']);
    }

    public function test_points_can_be_exchanged_for_a_personal_discount(): void
    {
        [$user] = $this->completedBooking(600);
        $this->actingAs($user)->getJson('/api/v1/loyalty')->assertOk();

        $response = $this->postJson('/api/v1/loyalty/redemptions', ['points' => 500])->assertCreated()->assertJsonPath('discount', 5);
        $this->assertDatabaseHas('coupons', ['user_id' => $user->id, 'code' => $response->json('coupon_code'), 'amount' => 5]);
        $this->assertSame(100, LoyaltyAccount::where('user_id', $user->id)->value('points_balance'));
    }

    public function test_referral_and_promotional_rewards_are_idempotent(): void
    {
        $user = User::factory()->create();
        foreach (['referrals' => 'referral', 'promotions' => 'promotion'] as $module => $type) {
            $reward = (new PlatformResource)->useModule($module);
            $reward->fill(['code' => strtoupper($type), 'name' => ucfirst($type), 'status' => 'active', 'amount' => 75])->save();
            $this->actingAs($user)->postJson('/api/v1/loyalty/rewards', ['type' => $type, 'code' => strtoupper($type)])->assertOk();
            $this->postJson('/api/v1/loyalty/rewards', ['type' => $type, 'code' => strtoupper($type)])->assertOk();
        }

        $this->assertSame(150, LoyaltyAccount::where('user_id', $user->id)->value('points_balance'));
    }

    /** @return array{User, Booking} */
    private function completedBooking(float $total = 100): array
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Review Coach', 'slug' => 'review-coach-'.Str::random(5), 'status' => 'active']);
        $origin = Terminal::create(['name' => 'Origin', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Destination', 'city' => 'Bulawayo', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare to Bulawayo', 'duration_minutes' => 300]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'REV-'.Str::random(5), 'model' => 'Coach', 'seat_capacity' => 1]);
        Seat::create(['bus_id' => $bus->id, 'number' => '1A']);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->subHours(6), 'arrives_at' => now()->subHour(), 'base_fare' => $total, 'currency' => 'USD', 'status' => 'completed']);
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BK'.Str::upper(Str::random(8)), 'company_id' => $company->id, 'trip_id' => $trip->id, 'user_id' => $user->id, 'contact_name' => $user->name, 'contact_email' => $user->email, 'contact_phone' => '+263771234567', 'subtotal' => $total, 'total' => $total, 'currency' => 'USD', 'status' => 'completed']);

        return [$user, $booking];
    }
}
