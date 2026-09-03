<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperatorApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_operator_application_can_be_approved(): void
    {
        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
        $applicant = User::factory()->create(['role' => 'passenger']);
        $applicant->assignRole('passenger');
        Sanctum::actingAs($applicant);
        $application = $this->postJson('/api/v1/operator-applications', ['name' => 'Intercity Coaches', 'registration_number' => 'REG-100', 'registration_information' => ['incorporated_on' => '2020-01-01'], 'currency' => 'USD', 'tax_number' => 'TAX-9', 'business_address' => ['city' => 'Harare'], 'contact_people' => [['name' => 'Owner', 'phone' => '+263771111111']], 'bank_details' => ['bank_name' => 'Bank', 'account_name' => 'Intercity', 'account_number' => '10001']])->assertCreated();
        $companyId = $application->json('id');
        foreach (['registration', 'operator_licence', 'transport_permit', 'insurance', 'bank_confirmation'] as $type) {
            $this->post('/api/v1/operator-applications/'.$companyId.'/documents', ['type' => $type, 'document' => UploadedFile::fake()->create("{$type}.pdf", 50, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        }
        $this->postJson('/api/v1/operator-applications/'.$companyId.'/submission')->assertOk()->assertJsonPath('status', 'under_review');
        $approver = User::factory()->create(['role' => 'operator_approval_officer']);
        $approver->assignRole('operator_approval_officer');
        Sanctum::actingAs($approver);

        $this->postJson('/api/v1/operator-applications/'.$companyId.'/decision', ['decision' => 'approved', 'commission_rate' => 8.5])->assertOk()->assertJsonPath('status', 'active');
        $this->assertSame('company_owner', $applicant->refresh()->role);
        $this->assertTrue($applicant->hasRole('company_owner'));
    }
}
