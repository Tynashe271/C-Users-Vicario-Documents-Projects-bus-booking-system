<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_settings_are_readable_by_anyone_and_only_platform_staff_can_change_them(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['role' => 'super_administrator']);
        $admin->assignRole('super_administrator');
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-settings']);
        $companyUser = User::factory()->create(['company_id' => $company->id, 'role' => 'company_administrator']);
        $companyUser->assignRole('company_administrator');

        // Anonymous read returns the defaults.
        $this->getJson('/api/v1/platform-settings')->assertOk()->assertJsonPath('platform_name', 'BusBooking')->assertJsonPath('maintenance_mode', false);

        Sanctum::actingAs($companyUser);
        $this->putJson('/api/v1/platform-settings', ['platform_name' => 'Hijacked'])->assertForbidden();

        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/platform-settings', ['platform_name' => 'Road Star Platform', 'supported_currencies' => ['USD', 'ZWG'], 'maintenance_mode' => true, 'maintenance_message' => 'Upgrading tonight.'])
            ->assertOk()->assertJsonPath('platform_name', 'Road Star Platform')->assertJsonPath('maintenance_mode', true);

        // A partial update preserves fields it didn't touch.
        $this->putJson('/api/v1/platform-settings', ['seat_lock_minutes' => 15])->assertOk()->assertJsonPath('platform_name', 'Road Star Platform')->assertJsonPath('seat_lock_minutes', 15);

        $this->getJson('/api/v1/platform-settings')->assertOk()->assertJsonPath('platform_name', 'Road Star Platform')->assertJsonPath('supported_currencies', ['USD', 'ZWG']);
    }

    public function test_platform_staff_can_upload_a_platform_logo(): void
    {
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['role' => 'super_administrator']);
        $admin->assignRole('super_administrator');
        Sanctum::actingAs($admin);

        $path = $this->post('/api/v1/platform-settings/logo', ['logo' => UploadedFile::fake()->create('logo.png', 50, 'image/png')], ['Accept' => 'application/json'])->assertOk()->json('logo_path');
        Storage::disk('public')->assertExists($path);
        $this->getJson('/api/v1/platform-settings')->assertOk()->assertJsonPath('logo_path', $path);
    }
}
