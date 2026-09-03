<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Bus;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanyOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_manages_profile_branches_and_staff_account_status(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star']);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_owner']);
        $owner->assignRole('company_owner');

        $this->actingAs($owner)->putJson('/api/v1/company/profile', ['trading_name' => 'Road Star Express', 'contact_people' => [['name' => 'Help Desk', 'email' => 'help@example.com']], 'bank_details' => ['bank_name' => 'City Bank', 'account_name' => 'Road Star', 'account_number' => '12345'], 'support_information' => ['phone' => '+263770000000'], 'booking_policy' => ['check_in_minutes' => 30], 'cancellation_policy' => ['refund_before_hours' => 24]])
            ->assertOk()->assertJsonPath('data.trading_name', 'Road Star Express')->assertJsonPath('bank_details.account_number', '12345');

        $branchId = $this->actingAs($owner)->postJson('/api/v1/branches', ['name' => 'Harare', 'code' => 'HRE', 'address' => ['city' => 'Harare'], 'operating_hours' => ['monday' => ['08:00', '18:00']]])->assertCreated()->json('data.id');
        $staff = User::factory()->create(['company_id' => $company->id, 'branch_id' => $branchId, 'role' => 'booking_clerk']);
        $staff->assignRole('booking_clerk');

        $this->actingAs($owner)->patchJson("/api/v1/staff/{$staff->id}/status", ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->actingAs($owner)->getJson("/api/v1/branches/{$branchId}/report")->assertOk()->assertJsonPath('staff_count', 1);
    }

    public function test_fleet_manager_builds_and_applies_a_complete_seat_layout(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star']);
        $manager = User::factory()->create(['company_id' => $company->id, 'role' => 'fleet_manager']);
        $manager->assignRole('fleet_manager');
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'LAY123', 'model' => 'Scania', 'seat_capacity' => 2]);
        $elements = [['kind' => 'seat', 'label' => '1A', 'row' => 1, 'column' => 1, 'position' => 'window'], ['kind' => 'aisle', 'row' => 1, 'column' => 2], ['kind' => 'seat', 'label' => '1B', 'row' => 1, 'column' => 3, 'position' => 'aisle'], ['kind' => 'toilet', 'row' => 2, 'column' => 3]];

        $layoutId = $this->actingAs($manager)->postJson('/api/v1/fleet/seat-layouts', ['name' => 'Executive 2', 'class' => 'executive', 'rows' => 2, 'columns' => 3, 'elements' => $elements])->assertCreated()->json('data.id');
        $this->actingAs($manager)->postJson("/api/v1/fleet/seat-layouts/{$layoutId}/buses/{$bus->id}")->assertOk()->assertJsonCount(2, 'data.seats');
        $this->assertDatabaseHas('seats', ['bus_id' => $bus->id, 'number' => '1A', 'position' => 'window']);
    }

    public function test_bus_documents_require_real_upload_and_platform_approval(): void
    {
        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star']);
        $manager = User::factory()->create(['company_id' => $company->id, 'role' => 'fleet_manager']);
        $manager->assignRole('fleet_manager');
        $approver = User::factory()->create(['role' => 'operator_approval_officer']);
        $approver->assignRole('operator_approval_officer');
        $bus = Bus::create(['company_id' => $company->id, 'registration_number' => 'DOC123', 'model' => 'Volvo', 'seat_capacity' => 40]);

        $documentId = $this->actingAs($manager)->post("/api/v1/fleet/buses/{$bus->id}/documents", ['document_type' => 'insurance', 'code' => 'INS-1', 'expires_on' => today()->addYear()->toDateString(), 'document' => UploadedFile::fake()->create('insurance.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('status', 'pending')->json('id');
        $this->actingAs($approver)->patchJson("/api/v1/fleet/documents/{$documentId}/verification", ['decision' => 'approved'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('bus_documents', ['id' => $documentId, 'verified_by' => $approver->id, 'status' => 'approved']);
    }

    public function test_branch_records_are_hidden_from_other_companies(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Road Star', 'slug' => 'road-star']);
        $other = Company::create(['name' => 'Other', 'slug' => 'other']);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_owner']);
        $owner->assignRole('company_owner');
        $branch = Branch::create(['company_id' => $other->id, 'name' => 'Private', 'code' => 'PVT', 'address' => [], 'operating_hours' => []]);

        $this->actingAs($owner)->getJson("/api/v1/branches/{$branch->id}")->assertNotFound();
    }
}
