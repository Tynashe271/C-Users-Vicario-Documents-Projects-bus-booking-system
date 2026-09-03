<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\LoyaltyAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyWalletAndCompanyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_passenger_wallet_supports_deposits_statements_and_security_controls(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/wallet/deposits', ['amount' => 75.50, 'reference' => 'PAY-100'])
            ->assertCreated()->assertJsonPath('wallet.available_balance', '75.50');
        $this->postJson('/api/v1/wallet/deposits', ['amount' => 75.50, 'reference' => 'PAY-100'])->assertCreated();
        $this->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('wallet.balance', '75.50')->assertJsonCount(1, 'transactions.data');
        $this->getJson('/api/v1/wallet/statement')->assertOk()->assertJsonPath('credits', 75.5);
        $this->putJson('/api/v1/wallet/security', ['pin' => '1234', 'is_frozen' => true, 'daily_spend_limit' => 50])->assertOk()->assertJsonMissingPath('wallet.security_pin')->assertJsonPath('wallet.is_frozen', true);
    }

    public function test_personal_referral_rewards_both_passengers_and_birthday_is_awarded_once(): void
    {
        $referrer = User::factory()->create();
        $friend = User::factory()->create(['date_of_birth' => today()->subYears(25)]);
        $code = $this->actingAs($referrer)->getJson('/api/v1/loyalty')->assertOk()->json('account.referral_code');

        $this->actingAs($friend)->postJson('/api/v1/loyalty/rewards', ['type' => 'referral', 'code' => $code])->assertOk();
        $this->getJson('/api/v1/loyalty')->assertOk()->assertJsonPath('account.points_balance', 200);
        $this->assertSame(100, LoyaltyAccount::where('user_id', $referrer->id)->value('points_balance'));
        $this->assertDatabaseCount('loyalty_transactions', 3);
    }

    public function test_company_profile_application_comments_and_status_lifecycle_are_available(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Complete Coaches', 'slug' => 'complete-coaches', 'status' => 'active']);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_owner']);
        $owner->assignRole('company_owner');

        $this->actingAs($owner)->putJson('/api/v1/company/profile', ['description' => 'Regional passenger transport.', 'social_links' => ['website' => 'https://example.com'], 'rescheduling_policy' => ['hours' => 12], 'luggage_policy' => ['kilograms' => 20], 'boarding_policy' => ['minutes' => 30], 'notification_templates' => ['booking' => 'Confirmed'], 'ticket_design' => ['accent' => '#123456'], 'settlement_information' => ['bank' => 'Example']])->assertOk()->assertJsonPath('data.description', 'Regional passenger transport.');
        $this->postJson("/api/v1/operator-applications/{$company->id}/comments", ['comment' => 'All documents supplied.'])->assertCreated();
        $this->getJson("/api/v1/operator-applications/{$company->id}")->assertOk()->assertJsonCount(1, 'comments');

        $admin = User::factory()->create(['role' => 'operator_approval_officer']);
        $admin->assignRole('operator_approval_officer');
        $this->actingAs($admin)->patchJson("/api/v1/admin/companies/{$company->id}/status", ['status' => 'suspended', 'reason' => 'Compliance review'])->assertOk()->assertJsonPath('status', 'suspended');
        $this->patchJson("/api/v1/admin/companies/{$company->id}/status", ['status' => 'active', 'reason' => 'Review completed'])->assertOk()->assertJsonPath('status', 'active');
    }
}
