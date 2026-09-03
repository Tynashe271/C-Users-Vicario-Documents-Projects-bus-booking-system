<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\TransportRoute;
use App\Models\Trip;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParcelManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_parcel_moves_through_paid_custody_chain_to_verified_collection(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [$company, $route, $trip] = $this->tripFixture();
        $clerk = User::factory()->create(['company_id' => $company->id, 'role' => 'company_administrator']);
        $clerk->assignRole('company_administrator');

        $created = $this->actingAs($clerk)->postJson('/api/v1/parcels', ['route_id' => $route->id, 'sender_name' => 'Alice Sender', 'sender_phone' => '+263770000001', 'receiver_name' => 'Bob Receiver', 'receiver_phone' => '+263770000002', 'description' => 'Books', 'weight_kg' => 5, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 20, 'prohibited_items_declared' => true])->assertCreated();
        $parcelId = $created->json('parcel.id');
        $trackingNumber = $created->json('parcel.tracking_number');
        $collectionCode = $created->json('collection_code');
        $amount = $created->json('parcel.amount');

        $this->actingAs($clerk)->postJson("/api/v1/parcels/{$parcelId}/assign", ['trip_id' => $trip->id])->assertOk()->assertJsonPath('status', 'assigned');
        $this->actingAs($clerk)->postJson("/api/v1/parcels/{$parcelId}/payment", ['payment_reference' => 'PAY-PARCEL-1', 'amount' => $amount])->assertOk()->assertJsonPath('payment_status', 'paid');
        foreach (['checked_in', 'loaded', 'in_transit', 'arrived'] as $event) {
            $this->actingAs($clerk)->postJson("/api/v1/parcels/{$parcelId}/events", ['event_type' => $event])->assertOk()->assertJsonPath('status', $event);
        }
        $this->actingAs($clerk)->postJson("/api/v1/parcels/{$parcelId}/collect", ['collection_code' => $collectionCode, 'proof_of_collection_path' => 'parcels/proof/parcel-1.jpg'])->assertOk()->assertJsonPath('status', 'collected');

        $this->getJson("/api/v1/parcels/track/{$trackingNumber}")->assertOk()->assertJsonPath('status', 'collected')->assertJsonMissing(['sender_name' => 'Alice Sender'])->assertJsonCount(8, 'events');
        $this->actingAs($clerk)->getJson('/api/v1/parcels/report')->assertOk()->assertJsonPath('total_parcels', 1)->assertJsonPath('collected', 1);
    }

    public function test_parcel_cannot_be_loaded_before_payment_or_by_another_company(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [$company, $route, $trip] = $this->tripFixture();
        $clerk = User::factory()->create(['company_id' => $company->id, 'role' => 'company_administrator']);
        $clerk->assignRole('company_administrator');
        $created = $this->actingAs($clerk)->postJson('/api/v1/parcels', ['route_id' => $route->id, 'sender_name' => 'Sender', 'sender_phone' => '0771', 'receiver_name' => 'Receiver', 'receiver_phone' => '0772', 'description' => 'Box', 'weight_kg' => 2, 'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 20, 'prohibited_items_declared' => true]);
        $parcelId = $created->json('parcel.id');
        $this->actingAs($clerk)->postJson("/api/v1/parcels/{$parcelId}/assign", ['trip_id' => $trip->id])->assertOk();
        $this->actingAs($clerk)->postJson("/api/v1/parcels/{$parcelId}/events", ['event_type' => 'checked_in'])->assertOk();
        $this->actingAs($clerk)->postJson("/api/v1/parcels/{$parcelId}/events", ['event_type' => 'loaded'])->assertConflict();

        $other = Company::create(['name' => 'Other', 'slug' => 'other']);
        $otherClerk = User::factory()->create(['company_id' => $other->id, 'role' => 'company_administrator']);
        $otherClerk->assignRole('company_administrator');
        $this->actingAs($otherClerk)->postJson("/api/v1/parcels/{$parcelId}/payment", ['payment_reference' => 'X', 'amount' => 1])->assertNotFound();
    }

    /** @return array{Company, TransportRoute, Trip} */
    private function tripFixture(): array
    {
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star', 'currency' => 'USD']);
        $origin = Terminal::create(['name' => 'Roadport', 'city' => 'Harare', 'country' => 'ZW']);
        $destination = Terminal::create(['name' => 'Intercity', 'city' => 'Bulawayo', 'country' => 'ZW']);
        $route = TransportRoute::create(['company_id' => $company->id, 'origin_terminal_id' => $origin->id, 'destination_terminal_id' => $destination->id, 'name' => 'Harare to Bulawayo', 'distance_km' => 440, 'duration_minutes' => 360]);
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'PARCEL123', 'model' => 'Scania', 'seat_capacity' => 50]);
        $trip = Trip::create(['company_id' => $company->id, 'route_id' => $route->id, 'bus_id' => $bus->id, 'departs_at' => now()->addDay(), 'arrives_at' => now()->addDay()->addHours(6), 'base_fare' => 30, 'currency' => 'USD', 'status' => 'available']);

        return [$company, $route, $trip];
    }
}
