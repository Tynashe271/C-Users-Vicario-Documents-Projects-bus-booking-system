<?php

namespace Tests\Feature;

use App\Models\SecurityAudit;
use App\Models\User;
use App\Notifications\PhoneVerificationCode;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_two_factor_requires_a_valid_challenge_before_issuing_a_token(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['password' => 'StrongPass123', 'role' => 'passenger']);
        $user->assignRole('passenger');
        Sanctum::actingAs($user);
        $setup = $this->postJson('/api/v1/two-factor/setup')->assertOk();
        $code = app(Google2FA::class)->getCurrentOtp($setup->json('secret'));
        $this->postJson('/api/v1/two-factor/confirm', ['code' => $code])->assertOk();

        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'StrongPass123', 'device_name' => 'Android'])->assertAccepted();
        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge_token' => $login->json('challenge_token'), 'code' => app(Google2FA::class)->getCurrentOtp($setup->json('secret'))])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_phone_verification_accepts_the_queued_one_time_code(): void
    {
        Notification::fake();
        $user = User::factory()->create(['phone' => '+263771234567']);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/phone/verification-code')->assertOk();
        $code = null;
        Notification::assertSentTo($user, PhoneVerificationCode::class, function (PhoneVerificationCode $notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });

        $this->postJson('/api/v1/phone/verify', ['code' => $code])->assertOk();
        $this->assertNotNull($user->refresh()->phone_verified_at);
    }

    public function test_changing_email_and_phone_removes_previous_verification(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'phone' => '+263771234567', 'phone_verified_at' => now()]);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/account', ['email' => 'changed@example.com', 'phone' => '+263778765432'])->assertOk();

        $user->refresh();
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->phone_verified_at);
    }

    public function test_passenger_can_manage_database_backed_preferences_and_private_documents(): void
    {
        $user = User::factory()->create(['role' => 'passenger']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/modules/passenger_preferences/records', [
            'name' => 'Passenger travel preferences',
            'code' => 'default',
            'status' => 'active',
            'data' => ['preferred_seat' => 'window', 'accessibility' => 'step-free boarding'],
        ])->assertCreated();
        $this->postJson('/api/v1/modules/travel_documents/records', [
            'name' => 'Passenger One',
            'code' => 'P1234567',
            'status' => 'active',
            'data' => ['type' => 'passport', 'country' => 'ZW'],
        ])->assertCreated();

        $this->assertDatabaseHas('passenger_preferences', ['user_id' => $user->id, 'code' => 'default']);
        $this->assertDatabaseHas('travel_documents', ['user_id' => $user->id, 'code' => 'P1234567']);
    }

    public function test_passenger_can_revoke_an_individual_device_session(): void
    {
        $user = User::factory()->create();
        $first = $user->createToken('Laptop')->accessToken;
        $second = $user->createToken('Phone')->accessToken;
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/account/devices/{$first->id}")->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $first->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $second->id]);
    }

    public function test_passenger_can_view_only_their_login_history(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        SecurityAudit::create(['user_id' => $user->id, 'event' => 'login_succeeded', 'identifier' => hash('sha256', $user->email), 'ip_address' => '127.0.0.1']);
        SecurityAudit::create(['user_id' => $otherUser->id, 'event' => 'login_failed', 'identifier' => hash('sha256', $otherUser->email), 'ip_address' => '192.0.2.1']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/account/login-history')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'login_succeeded')
            ->assertJsonMissing(['ip_address' => '192.0.2.1']);
    }

    public function test_passenger_can_deactivate_account_with_current_password(): void
    {
        $user = User::factory()->create(['password' => 'StrongPass123']);
        $user->createToken('Laptop');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/account/deactivation', ['password' => 'StrongPass123'])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'inactive']);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }
}
