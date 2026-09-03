<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecurringScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_weekly_schedule_expands_idempotently_into_trips(): void
    {
        $this->travelTo('2026-09-07 08:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Operator', 'slug' => 'operator']);
        $user = User::factory()->create(['company_id' => $company->id, 'role' => 'operations_manager']);
        $user->assignRole('operations_manager');
        $origin = Terminal::create(['name' => 'Harare', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Bulawayo', 'city' => 'Bulawayo', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare to Bulawayo', 'duration_minutes' => 360]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'ABC123', 'model' => 'Scania', 'seat_capacity' => 50]);
        foreach (['insurance', 'permit'] as $documentType) {
            $bus->documents()->create(['company_id' => $company->id, 'document_type' => $documentType, 'file_path' => "documents/{$documentType}.pdf", 'expires_on' => today()->addYear(), 'status' => 'approved']);
        }
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/schedules', ['name' => 'Monday service', 'route_id' => $route->id, 'bus_id' => $bus->id, 'days_of_week' => [1], 'departure_time' => '09:00', 'timezone' => 'Africa/Harare', 'starts_on' => '2026-09-07', 'fare' => 25, 'currency' => 'USD', 'generate_days' => 7]);

        $response->assertCreated()->assertJsonPath('trips_created', 2);
        $this->artisan('trips:expand', ['--days' => 7])->assertSuccessful();
        $this->assertDatabaseCount('trips', 2);
    }
}
