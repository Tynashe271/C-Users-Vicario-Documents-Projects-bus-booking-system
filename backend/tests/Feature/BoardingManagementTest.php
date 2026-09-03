<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Seat;
use App\Models\Terminal;
use App\Models\Ticket;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BoardingManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_terminal_staff_can_check_in_board_and_view_the_manifest(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [$company, $trip, $ticket] = $this->ticketFixture();
        $officer = User::factory()->create(['company_id' => $company->id, 'role' => 'terminal_officer']);
        $officer->assignRole('terminal_officer');

        $this->actingAs($officer)->postJson('/api/v1/boarding/scans', ['code' => $ticket->qr_token, 'action' => 'check_in', 'device_id' => 'gate-1'])
            ->assertOk()
            ->assertJsonPath('ticket_number', $ticket->ticket_number)
            ->assertJsonPath('seat_number', '1A');

        $this->actingAs($officer)->postJson('/api/v1/boarding/scans', ['code' => $ticket->ticket_number, 'action' => 'check_in'])->assertConflict();

        $this->actingAs($officer)->postJson('/api/v1/boarding/scans', ['code' => $ticket->ticket_number, 'action' => 'board'])
            ->assertOk()
            ->assertJsonPath('passenger_name', 'Jane Traveller');

        $this->actingAs($officer)->getJson("/api/v1/trips/{$trip->id}/manifest")
            ->assertOk()
            ->assertJsonPath('counts.passengers', 1)
            ->assertJsonPath('counts.checked_in', 1)
            ->assertJsonPath('counts.boarded', 1)
            ->assertJsonPath('counts.absent', 0);

        $this->assertDatabaseHas('boarding_scans', ['company_id' => $company->id, 'user_id' => $officer->id, 'code' => $ticket->ticket_number.':board']);
    }

    public function test_terminal_staff_cannot_scan_another_company_ticket(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [, , $ticket] = $this->ticketFixture();
        $otherCompany = Company::create(['name' => 'Other Operator', 'slug' => 'other-operator']);
        $officer = User::factory()->create(['company_id' => $otherCompany->id, 'role' => 'terminal_officer']);
        $officer->assignRole('terminal_officer');

        $this->actingAs($officer)->postJson('/api/v1/boarding/scans', ['code' => $ticket->qr_token, 'action' => 'check_in'])->assertNotFound();
    }

    public function test_offline_scans_sync_in_batch_and_report_a_per_scan_outcome(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [$company, , $ticket] = $this->ticketFixture();
        $officer = User::factory()->create(['company_id' => $company->id, 'role' => 'terminal_officer']);
        $officer->assignRole('terminal_officer');

        $response = $this->actingAs($officer)->postJson('/api/v1/boarding/scans/sync', ['scans' => [
            ['code' => $ticket->qr_token, 'action' => 'check_in', 'device_id' => 'gate-1', 'offline_recorded_at' => now()->subMinutes(20)->toIso8601String()],
            ['code' => 'UNKNOWN-CODE', 'action' => 'check_in'],
        ]])->assertOk();

        $response->assertJsonPath('applied', 1)->assertJsonPath('failed', 1);
        $this->assertSame('applied', $response->json('results.0.status'));
        $this->assertSame('failed', $response->json('results.1.status'));
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id]);
        $this->assertNotNull($ticket->fresh()->checked_in_at);
    }

    /** @return array{Company, Trip, Ticket} */
    private function ticketFixture(): array
    {
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star']);
        $origin = Terminal::create(['name' => 'Roadport', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Intercity', 'city' => 'Bulawayo', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare to Bulawayo', 'duration_minutes' => 360]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'BOARD123', 'model' => 'Scania', 'seat_capacity' => 1]);
        $seat = Seat::create(['bus_id' => $bus->id, 'number' => '1A']);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDay(), 'arrives_at' => now()->addDay()->addHours(6), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'boarding']);
        $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BKBOARD1', 'company_id' => $company->id, 'trip_id' => $trip->id, 'contact_name' => 'Jane Traveller', 'contact_phone' => '+263770000000', 'subtotal' => 30, 'total' => 30, 'currency' => 'USD', 'status' => 'confirmed']);
        $passenger = BookingPassenger::create(['booking_id' => $booking->id, 'trip_id' => $trip->id, 'seat_id' => $seat->id, 'full_name' => 'Jane Traveller', 'type' => 'adult', 'fare' => 30]);
        $ticket = Ticket::create(['public_id' => Str::uuid(), 'ticket_number' => 'TKBOARD1', 'booking_passenger_id' => $passenger->id, 'qr_token' => hash('sha256', 'boarding-test')]);

        return [$company, $trip, $ticket];
    }
}
