<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic feature test example.
     */
    public function test_passenger_can_register_login_and_logout(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $registration = $this->postJson('/api/v1/auth/register', [
            'name' => 'Passenger One', 'email' => 'passenger@example.com', 'phone' => '+263771234567',
            'password' => 'StrongPass123', 'password_confirmation' => 'StrongPass123', 'device_name' => 'PHPUnit', 'terms_accepted' => true,
        ]);
        $registration->assertCreated()->assertJsonStructure(['user' => ['id', 'email'], 'token']);
        $this->assertDatabaseHas('terms_acceptances', ['user_id' => $registration->json('user.id'), 'status' => 'accepted']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'No Terms', 'email' => 'no-terms@example.com', 'password' => 'StrongPass123',
            'password_confirmation' => 'StrongPass123', 'device_name' => 'PHPUnit',
        ])->assertUnprocessable()->assertJsonValidationErrors('terms_accepted');

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'passenger@example.com', 'password' => 'StrongPass123', 'device_name' => 'PHPUnit']);
        $login->assertOk()->assertJsonStructure(['token']);
        $this->withToken($login->json('token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('email', 'passenger@example.com');

        $this->postJson('/api/v1/auth/login', ['login' => '+263771234567', 'password' => 'StrongPass123', 'device_name' => 'Phone login'])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_passenger_registration_requires_a_phone_number(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Passenger One',
            'email' => 'passenger@example.com',
            'password' => 'StrongPass123',
            'password_confirmation' => 'StrongPass123',
            'device_name' => 'PHPUnit',
            'terms_accepted' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_company_users_can_login_from_the_same_browser_without_device_collision(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::query()->create([
            'name' => 'Shared Device Coaches',
            'slug' => 'shared-device-coaches',
            'registration_number' => 'SHARED-001',
            'status' => 'active',
            'currency' => 'USD',
        ]);
        $driver = User::factory()->create(['company_id' => $company->id, 'role' => 'driver', 'password' => 'StrongPass123']);
        $agent = User::factory()->create(['company_id' => $company->id, 'role' => 'booking_clerk', 'password' => 'StrongPass123']);

        $payload = ['password' => 'StrongPass123', 'device_name' => 'Shared Browser'];

        $this->postJson('/api/v1/auth/login', $payload + ['email' => $driver->email])->assertOk();
        $this->postJson('/api/v1/auth/login', $payload + ['email' => $agent->email])->assertOk();
        $this->assertDatabaseCount('login_devices', 2);
    }
}
