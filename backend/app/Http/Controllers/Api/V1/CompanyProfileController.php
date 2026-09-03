<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PlatformResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CompanyProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $company = $this->company($request);

        return response()->json(['data' => $company, 'bank_details' => $company->bank_details]);
    }

    public function update(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'], 'trading_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'registration_information' => ['sometimes', 'array'], 'business_address' => ['sometimes', 'array'],
            'contact_people' => ['sometimes', 'array', 'max:20'], 'contact_people.*.name' => ['required_with:contact_people', 'string', 'max:150'],
            'contact_people.*.email' => ['nullable', 'email'], 'contact_people.*.phone' => ['nullable', 'string', 'max:30'],
            'bank_details' => ['sometimes', 'array'], 'bank_details.account_name' => ['required_with:bank_details', 'string', 'max:150'],
            'bank_details.account_number' => ['required_with:bank_details', 'string', 'max:100'], 'bank_details.bank_name' => ['required_with:bank_details', 'string', 'max:150'],
            'support_information' => ['sometimes', 'array'], 'booking_policy' => ['sometimes', 'array'], 'cancellation_policy' => ['sometimes', 'array'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'], 'social_links' => ['sometimes', 'array'],
            'rescheduling_policy' => ['sometimes', 'array'], 'luggage_policy' => ['sometimes', 'array'], 'boarding_policy' => ['sometimes', 'array'],
            'notification_templates' => ['sometimes', 'array'], 'ticket_design' => ['sometimes', 'array'], 'settlement_information' => ['sometimes', 'array'],
        ]);
        if (array_key_exists('bank_details', $validated)) {
            (new PlatformResource)->useModule('audit_logs')->fill([
                'company_id' => $company->id, 'user_id' => $request->user()->id, 'code' => 'company.bank_details.changed.'.Str::uuid(), 'name' => 'Company bank details changed', 'status' => 'recorded',
                'data' => ['record_type' => Company::class, 'record_id' => $company->id, 'ip_address' => $request->ip()],
            ])->save();
        }
        $company->update($validated);

        return response()->json(['data' => $company->refresh(), 'bank_details' => $company->bank_details]);
    }

    public function logo(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $validated = $request->validate(['logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        $path = $validated['logo']->store("companies/{$company->id}/branding", ['disk' => 'public']);
        $company->update(['logo_path' => $path]);

        return response()->json(['data' => $company->refresh()]);
    }

    private function company(Request $request): Company
    {
        abort_unless($request->user()->company_id && $request->user()->can('companies.manage'), 403);

        return $request->user()->company()->firstOrFail();
    }
}
