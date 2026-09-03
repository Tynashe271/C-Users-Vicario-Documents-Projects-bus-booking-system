<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReviewModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reported_review_can_be_moderated_kept_or_removed_and_a_company_can_respond(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-moderation']);
        $marketer = User::factory()->create(['company_id' => $company->id, 'role' => 'marketing_manager']);
        $marketer->assignRole('marketing_manager');
        $author = User::factory()->create();
        $reporter = User::factory()->create();
        $review = Review::create(['company_id' => $company->id, 'user_id' => $author->id, 'code' => 'review-1', 'name' => 'Trip review', 'status' => 'active', 'amount' => 4.5, 'cleanliness' => 5, 'comfort' => 4, 'punctuality' => 5, 'driver_professionalism' => 4, 'customer_service' => 4, 'overall_experience' => 5, 'comment' => 'Great trip but a bit loud driver.']);

        Sanctum::actingAs($reporter);
        $this->postJson("/api/v1/reviews/{$review->id}/report", ['reason' => 'Contains harassment'])->assertOk()->assertJsonPath('report_reason', 'Contains harassment');
        $this->postJson("/api/v1/reviews/{$review->id}/report", ['reason' => 'Again'])->assertStatus(409);

        Sanctum::actingAs($marketer);
        $this->getJson('/api/v1/review-moderation?status=reported')->assertOk()->assertJsonPath('data.0.id', $review->id);
        $this->postJson("/api/v1/reviews/{$review->id}/response", ['response' => 'Thanks for the feedback, we have retrained the driver.'])->assertOk()->assertJsonPath('company_response', 'Thanks for the feedback, we have retrained the driver.');
        $this->postJson("/api/v1/reviews/{$review->id}/moderation/approval")->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('reported_at', null);

        $secondReview = Review::create(['company_id' => $company->id, 'user_id' => $author->id, 'code' => 'review-2', 'name' => 'Trip review', 'status' => 'active', 'amount' => 1, 'cleanliness' => 1, 'comfort' => 1, 'punctuality' => 1, 'driver_professionalism' => 1, 'customer_service' => 1, 'overall_experience' => 1, 'comment' => 'Abusive content here.']);
        $this->postJson("/api/v1/reviews/{$secondReview->id}/moderation/removal", ['reason' => 'Violates community guidelines'])->assertOk()->assertJsonPath('status', 'removed');
        $this->postJson("/api/v1/reviews/{$secondReview->id}/moderation/warning", ['reason' => 'Abusive language'])->assertCreated();
        $this->assertDatabaseHas('security_events', ['user_id' => $author->id, 'name' => 'User warning']);

        $analytics = $this->getJson('/api/v1/review-moderation/analytics')->assertOk()->json();
        // The removed review is excluded from analytics; only the kept one (overall_experience 5) counts.
        $this->assertSame(1, $analytics['total_reviews']);
        $this->assertEqualsWithDelta(5.0, $analytics['average_overall'], 0.001);
    }
}
