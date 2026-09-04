<?php

namespace Tests\Feature;

use App\Jobs\DeliverPlatformNotification;
use App\Jobs\DeliverWebhookNotification;
use App\Jobs\GenerateReportExport;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BackgroundProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_and_webhook_jobs_are_dispatched_onto_their_own_named_queues(): void
    {
        Queue::fake([DeliverPlatformNotification::class, DeliverWebhookNotification::class, GenerateReportExport::class]);

        DeliverPlatformNotification::dispatch(1);
        DeliverWebhookNotification::dispatch(1);
        GenerateReportExport::dispatch(1);

        Queue::assertPushedOn('notifications', DeliverPlatformNotification::class);
        Queue::assertPushedOn('webhooks', DeliverWebhookNotification::class);
        Queue::assertPushedOn('reports', GenerateReportExport::class);
    }

    public function test_a_company_user_can_export_their_own_bookings_and_download_the_finished_csv(): void
    {
        Storage::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-export']);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_owner']);
        $owner->assignRole('company_owner');
        $trip = $this->tripFor($company);
        Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKEXP1', 'company_id' => $company->id, 'trip_id' => $trip->id, 'contact_name' => 'P', 'contact_phone' => '+263770000000', 'subtotal' => 100, 'total' => 100, 'currency' => 'USD', 'status' => 'confirmed', 'created_at' => today()]);
        Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKEXP2', 'company_id' => $company->id, 'trip_id' => $trip->id, 'contact_name' => 'P', 'contact_phone' => '+263770000001', 'subtotal' => 100, 'total' => 100, 'currency' => 'USD', 'status' => 'confirmed', 'created_at' => today()->subMonths(3)]);
        $otherCompany = Company::create(['name' => 'Other', 'slug' => 'other-export']);
        Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKEXP3', 'company_id' => $otherCompany->id, 'trip_id' => $this->tripFor($otherCompany)->id, 'contact_name' => 'P', 'contact_phone' => '+263770000002', 'subtotal' => 100, 'total' => 100, 'currency' => 'USD', 'status' => 'confirmed', 'created_at' => today()]);

        Sanctum::actingAs($owner);
        $created = $this->postJson('/api/v1/admin/report-exports', ['type' => 'bookings', 'from' => today()->subDays(7)->toDateString(), 'to' => today()->toDateString()])->assertCreated()->json();
        $this->assertSame('queued', $created['status']);
        $exportId = $created['id'];

        // Sync queue runs the job inline; a real deploy's worker would pick it up asynchronously.
        $ready = $this->getJson("/api/v1/admin/report-exports/{$exportId}")->assertOk()->json();
        $this->assertSame('ready', $ready['status']);
        $this->assertSame(1, $ready['data']['row_count']);

        $response = $this->get("/api/v1/admin/report-exports/{$exportId}/download")->assertOk();
        $response->assertHeader('content-disposition');
        $content = $response->streamedContent();
        $this->assertStringContainsString('BKEXP1', $content);
        $this->assertStringNotContainsString('BKEXP2', $content);
        $this->assertStringNotContainsString('BKEXP3', $content);
    }

    public function test_a_company_user_cannot_see_or_download_another_companys_export(): void
    {
        Storage::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $owner = Company::create(['name' => 'Road Star', 'slug' => 'road-star-export-iso']);
        $intruder = Company::create(['name' => 'Rival', 'slug' => 'rival-export-iso']);
        $ownerUser = User::factory()->create(['company_id' => $owner->id, 'role' => 'company_owner']);
        $ownerUser->assignRole('company_owner');
        $intruderUser = User::factory()->create(['company_id' => $intruder->id, 'role' => 'company_owner']);
        $intruderUser->assignRole('company_owner');

        Sanctum::actingAs($ownerUser);
        $exportId = $this->postJson('/api/v1/admin/report-exports', ['type' => 'bookings'])->assertCreated()->json('id');

        Sanctum::actingAs($intruderUser);
        $this->getJson("/api/v1/admin/report-exports/{$exportId}")->assertNotFound();
        $this->get("/api/v1/admin/report-exports/{$exportId}/download")->assertNotFound();
    }

    public function test_only_a_super_administrator_can_view_queue_health_and_manage_failed_jobs(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-queue']);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_owner']);
        $owner->assignRole('company_owner');
        $superAdmin = User::factory()->create(['role' => 'super_administrator']);
        $superAdmin->assignRole('super_administrator');

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/admin/queue/health')->assertForbidden();

        Sanctum::actingAs($superAdmin);
        $health = $this->getJson('/api/v1/admin/queue/health')->assertOk()->json();
        $this->assertArrayHasKey('notifications', $health['queues']);
        $this->assertArrayHasKey('webhooks', $health['queues']);
        $this->assertArrayHasKey('reports', $health['queues']);
        $this->assertSame(0, $health['failed_jobs']['count']);
    }

    public function test_a_failed_job_can_be_listed_retried_and_forgotten(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $superAdmin = User::factory()->create(['role' => 'super_administrator']);
        $superAdmin->assignRole('super_administrator');
        DB::table('failed_jobs')->insert([
            'uuid' => 'test-failed-job-uuid', 'connection' => 'sync', 'queue' => 'notifications',
            'payload' => json_encode(['displayName' => DeliverPlatformNotification::class]),
            'exception' => "RuntimeException: something broke\n#0 stack trace...",
            'failed_at' => now(),
        ]);

        Sanctum::actingAs($superAdmin);
        $listed = $this->getJson('/api/v1/admin/queue/failed-jobs')->assertOk()->json();
        $this->assertSame('test-failed-job-uuid', $listed['data'][0]['uuid']);
        $this->assertSame(DeliverPlatformNotification::class, $listed['data'][0]['job_class']);

        $this->postJson('/api/v1/admin/queue/failed-jobs/does-not-exist/retry')->assertNotFound();
        $this->postJson('/api/v1/admin/queue/failed-jobs/test-failed-job-uuid/retry')->assertOk()->assertJsonPath('retried', true);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'test-failed-job-uuid']);
    }

    public function test_forgetting_a_failed_job_removes_it(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $superAdmin = User::factory()->create(['role' => 'super_administrator']);
        $superAdmin->assignRole('super_administrator');
        DB::table('failed_jobs')->insert(['uuid' => 'forget-me', 'connection' => 'sync', 'queue' => 'webhooks', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

        Sanctum::actingAs($superAdmin);
        $this->deleteJson('/api/v1/admin/queue/failed-jobs/forget-me')->assertStatus(204);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'forget-me']);
    }

    private function tripFor(Company $company): Trip
    {
        $origin = Terminal::create(['name' => 'A '.Str::random(4), 'city' => 'A', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'B '.Str::random(4), 'city' => 'B', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Route '.Str::random(4), 'duration_minutes' => 240]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'BG-'.Str::random(6), 'model' => 'Scania', 'seat_capacity' => 10]);

        return Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(2), 'arrives_at' => now()->addDays(2)->addHours(4), 'base_fare' => 100, 'currency' => 'USD', 'status' => 'published']);
    }
}
