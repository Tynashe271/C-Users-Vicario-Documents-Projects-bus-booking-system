<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\Company;
use App\Models\Seat;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_lifecycle_and_transaction_limit_gate_bookings(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star-agents']);
        $manager = User::factory()->create(['company_id' => $company->id, 'role' => 'company_administrator']);
        $manager->assignRole('company_administrator');
        $agentUser = User::factory()->create(['company_id' => $company->id, 'role' => 'booking_clerk']);
        $agentUser->assignRole('booking_clerk');

        [$route, $bus] = $this->routeAndBus($company);
        // A distinct seat per attempt below: a failed booking attempt doesn't release its seat lock,
        // so reusing one seat across attempts would spuriously fail on "seat already held", not on
        // the thing each step is actually testing.
        [$seatA, $seatB, $seatC, $seatD] = ['1A', '1B', '1C', '1D'];
        $seats = collect([$seatA, $seatB, $seatC, $seatD])->mapWithKeys(fn (string $number) => [$number => Seat::create(['bus_id' => $bus->id, 'number' => $number])]);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDays(2), 'arrives_at' => now()->addDays(2)->addHours(4), 'base_fare' => 100, 'currency' => 'USD', 'status' => 'published']);

        // Before verification, the agent's own booking attempt is rejected.
        Sanctum::actingAs($agentUser);
        $lock = $this->postJson("/api/v1/trips/{$trip->id}/seat-locks", ['seat_ids' => [$seats[$seatA]->id]])->assertCreated();
        $this->postJson("/api/v1/trips/{$trip->id}/bookings", $this->bookingPayload($lock->json('token'), $seats[$seatA]->id))->assertForbidden();

        Sanctum::actingAs($manager);
        $agentId = $this->postJson('/api/v1/agents', ['user_id' => $agentUser->id, 'name' => 'Agent One', 'transaction_limit' => 50])
            ->assertCreated()->assertJsonPath('status', 'pending')->json('id');
        $this->postJson("/api/v1/agents/{$agentId}/verification", ['decision' => 'approved'])->assertOk()->assertJsonPath('status', 'approved');

        // Approved, but this booking's $100 total exceeds the agent's $50 limit.
        Sanctum::actingAs($agentUser);
        $lock = $this->postJson("/api/v1/trips/{$trip->id}/seat-locks", ['seat_ids' => [$seats[$seatB]->id]])->assertCreated();
        $this->postJson("/api/v1/trips/{$trip->id}/bookings", $this->bookingPayload($lock->json('token'), $seats[$seatB]->id))->assertUnprocessable();

        Sanctum::actingAs($manager);
        $this->putJson("/api/v1/agents/{$agentId}/transaction-limit", ['transaction_limit' => 500])->assertOk()->assertJsonPath('amount', '500.00');

        Sanctum::actingAs($agentUser);
        $lock = $this->postJson("/api/v1/trips/{$trip->id}/seat-locks", ['seat_ids' => [$seats[$seatC]->id]])->assertCreated();
        $this->postJson("/api/v1/trips/{$trip->id}/bookings", $this->bookingPayload($lock->json('token'), $seats[$seatC]->id))->assertCreated();

        Sanctum::actingAs($manager);
        $report = $this->getJson("/api/v1/agents/{$agentId}/daily-report")->assertOk()->json();
        $this->assertSame(1, $report['bookings']);
        $this->postJson("/api/v1/agents/{$agentId}/suspension", ['reason' => 'Under investigation'])->assertOk()->assertJsonPath('status', 'suspended');

        Sanctum::actingAs($agentUser);
        $lock = $this->postJson("/api/v1/trips/{$trip->id}/seat-locks", ['seat_ids' => [$seats[$seatD]->id]])->assertCreated();
        $this->postJson("/api/v1/trips/{$trip->id}/bookings", $this->bookingPayload($lock->json('token'), $seats[$seatD]->id))->assertForbidden();
    }

    /** @return array{TransportRoute, Bus} */
    private function routeAndBus(Company $company): array
    {
        $origin = Terminal::create(['name' => 'Harare', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Mutare', 'city' => 'Mutare', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare-Mutare', 'duration_minutes' => 240]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'AGT-'.str()->random(6), 'model' => 'Scania', 'seat_capacity' => 5]);

        return [$route, $bus];
    }

    /** @return array<string, mixed> */
    private function bookingPayload(string $lockToken, int $seatId): array
    {
        return [
            'lock_token' => $lockToken, 'contact_name' => 'Agent Sale', 'contact_email' => 'agent@example.com', 'contact_phone' => '+263771234567',
            'source' => 'agent', 'booking_terms_accepted' => true,
            'passengers' => [['seat_id' => $seatId, 'full_name' => 'Walk-in Passenger', 'phone' => '+263771234567', 'email' => 'passenger@example.com', 'type' => 'adult']],
        ];
    }
}
