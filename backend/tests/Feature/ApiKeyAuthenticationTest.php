<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiKeyAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_administrator_issues_a_scoped_key_that_authenticates_a_partner_request(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-apikey']);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_administrator']);
        $owner->assignRole('company_administrator');
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKAPI1', 'company_id' => $company->id, 'trip_id' => $this->tripId($company), 'contact_name' => 'P', 'contact_phone' => '+263770000000', 'subtotal' => 30, 'total' => 30, 'currency' => 'USD', 'status' => 'confirmed']);

        Sanctum::actingAs($owner);
        $created = $this->postJson('/api/v1/admin/api-clients', ['name' => 'Accounting Integration', 'role' => 'company_administrator', 'abilities' => ['bookings.read']])
            ->assertCreated()->json();
        $this->assertArrayNotHasKey('key_hash', $created['client']);
        $apiKey = $created['api_key'];
        $clientId = $created['client']['client_id'];

        // Not usable without a key at all, or a valid-looking one whose secret is wrong.
        $this->getJson("/api/v1/partner/bookings/{$booking->id}")->assertUnauthorized();
        $this->withHeaders(['X-Api-Key' => $clientId.'.wrong-secret'])->getJson("/api/v1/partner/bookings/{$booking->id}")->assertUnauthorized();

        // The real key authenticates and resolves a company-scoped user, exactly like a real login.
        $response = $this->withHeaders(['X-Api-Key' => $apiKey])->getJson("/api/v1/partner/bookings/{$booking->id}")->assertOk();
        $response->assertJsonPath('reference', 'BKAPI1');
        $this->assertDatabaseHas('api_clients', ['client_id' => $clientId]);
        $this->assertNotNull(ApiClient::where('client_id', $clientId)->first()->last_used_at);

        // A booking belonging to another company is invisible to this key, same as a real login.
        $otherCompany = Company::create(['name' => 'Other', 'slug' => 'other-apikey']);
        $otherBooking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKAPI2', 'company_id' => $otherCompany->id, 'trip_id' => $this->tripId($otherCompany), 'contact_name' => 'P', 'contact_phone' => '+263770000001', 'subtotal' => 30, 'total' => 30, 'currency' => 'USD', 'status' => 'confirmed']);
        $this->withHeaders(['X-Api-Key' => $apiKey])->getJson("/api/v1/partner/bookings/{$otherBooking->id}")->assertForbidden();

        // The key's own declared scope (bookings.read only) blocks a route needing a different one.
        $this->withHeaders(['X-Api-Key' => $apiKey])->getJson('/api/v1/partner/parcels/report')->assertForbidden();
    }

    public function test_a_revoked_key_stops_authenticating_and_suspends_its_service_account(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-apikey-revoke']);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_administrator']);
        $owner->assignRole('company_administrator');
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKAPI3', 'company_id' => $company->id, 'trip_id' => $this->tripId($company), 'contact_name' => 'P', 'contact_phone' => '+263770000000', 'subtotal' => 30, 'total' => 30, 'currency' => 'USD', 'status' => 'confirmed']);

        Sanctum::actingAs($owner);
        $created = $this->postJson('/api/v1/admin/api-clients', ['name' => 'Temp Integration', 'role' => 'company_administrator', 'abilities' => ['bookings.read']])->assertCreated()->json();
        $apiKey = $created['api_key'];
        $apiClientId = $created['client']['id'];

        $this->withHeaders(['X-Api-Key' => $apiKey])->getJson("/api/v1/partner/bookings/{$booking->id}")->assertOk();
        $this->postJson("/api/v1/admin/api-clients/{$apiClientId}/revocation")->assertOk()->assertJsonPath('status', 'revoked');
        $this->withHeaders(['X-Api-Key' => $apiKey])->getJson("/api/v1/partner/bookings/{$booking->id}")->assertUnauthorized();
        $this->assertDatabaseHas('users', ['id' => $created['client']['user_id'], 'status' => 'suspended']);

        // Rotation on an already-revoked key is refused rather than silently reactivating it.
        $this->postJson("/api/v1/admin/api-clients/{$apiClientId}/rotation")->assertStatus(409);
    }

    public function test_rotating_a_key_invalidates_the_old_secret_and_issues_a_working_new_one(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-apikey-rotate']);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_administrator']);
        $owner->assignRole('company_administrator');
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKAPI4', 'company_id' => $company->id, 'trip_id' => $this->tripId($company), 'contact_name' => 'P', 'contact_phone' => '+263770000000', 'subtotal' => 30, 'total' => 30, 'currency' => 'USD', 'status' => 'confirmed']);

        Sanctum::actingAs($owner);
        $created = $this->postJson('/api/v1/admin/api-clients', ['name' => 'Rotating Integration', 'role' => 'company_administrator', 'abilities' => ['bookings.read']])->assertCreated()->json();
        $oldKey = $created['api_key'];
        $apiClientId = $created['client']['id'];

        $rotated = $this->postJson("/api/v1/admin/api-clients/{$apiClientId}/rotation")->assertOk()->json();
        $this->assertNotSame($oldKey, $rotated['api_key']);

        $this->withHeaders(['X-Api-Key' => $oldKey])->getJson("/api/v1/partner/bookings/{$booking->id}")->assertUnauthorized();
        $this->withHeaders(['X-Api-Key' => $rotated['api_key']])->getJson("/api/v1/partner/bookings/{$booking->id}")->assertOk();
    }

    public function test_a_key_restricted_by_ip_allowlist_is_rejected_from_another_address(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-apikey-ip']);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_administrator']);
        $owner->assignRole('company_administrator');
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKAPI5', 'company_id' => $company->id, 'trip_id' => $this->tripId($company), 'contact_name' => 'P', 'contact_phone' => '+263770000000', 'subtotal' => 30, 'total' => 30, 'currency' => 'USD', 'status' => 'confirmed']);

        Sanctum::actingAs($owner);
        $created = $this->postJson('/api/v1/admin/api-clients', ['name' => 'IP Restricted', 'role' => 'company_administrator', 'abilities' => ['bookings.read'], 'allowed_ips' => ['203.0.113.9']])->assertCreated()->json();

        $this->withHeaders(['X-Api-Key' => $created['api_key']])->getJson("/api/v1/partner/bookings/{$booking->id}")->assertForbidden();
    }

    public function test_only_platform_security_staff_can_issue_a_platform_wide_key_and_company_staff_cannot(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-apikey-platform']);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_administrator']);
        $owner->assignRole('company_administrator');
        $security = User::factory()->create(['role' => 'security_administrator']);
        $security->assignRole('security_administrator');

        Sanctum::actingAs($owner);
        // A company admin cannot even declare company_id: it's their own, implicitly.
        $this->postJson('/api/v1/admin/api-clients', ['name' => 'Cross-tenant attempt', 'role' => 'company_administrator', 'company_id' => $company->id])->assertUnprocessable();

        Sanctum::actingAs($security);
        $created = $this->postJson('/api/v1/admin/api-clients', ['name' => 'Platform Aggregator', 'role' => 'operations_manager', 'company_id' => null])->assertCreated()->json();
        $this->assertNull($created['client']['company_id']);
    }

    private function tripId(Company $company): int
    {
        $origin = Terminal::create(['name' => 'Harare '.Str::random(4), 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Mutare '.Str::random(4), 'city' => 'Mutare', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Route '.Str::random(4), 'duration_minutes' => 240]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'API-'.Str::random(6), 'model' => 'Scania', 'seat_capacity' => 10]);

        return Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(2), 'arrives_at' => now()->addDays(2)->addHours(4), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'published'])->id;
    }
}
