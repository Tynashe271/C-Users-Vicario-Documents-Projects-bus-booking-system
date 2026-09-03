<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PlatformResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OperatorApplicationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_if($request->user()->company_id, 409, 'This account is already linked to a company.');
        $validated = $request->validate(['name' => ['required', 'string', 'max:255'], 'trading_name' => ['nullable', 'string', 'max:255'], 'registration_number' => ['required', 'string', 'max:100', 'unique:companies,registration_number'], 'registration_information' => ['required', 'array'], 'currency' => ['required', 'string', 'size:3'], 'tax_number' => ['required', 'string', 'max:100'], 'business_address' => ['required', 'array'], 'contact_people' => ['required', 'array', 'min:1'], 'bank_details' => ['required', 'array']]);
        $company = DB::transaction(function () use ($request, $validated): Company {
            $company = Company::create([...$validated, 'slug' => str($validated['name'])->slug().'-'.str()->lower(str()->random(6)), 'currency' => strtoupper($validated['currency']), 'status' => 'application_draft']);
            $request->user()->forceFill(['company_id' => $company->id, 'role' => 'operator_applicant'])->save();
            $request->user()->syncRoles('operator_applicant');

            return $company;
        });

        return response()->json($company, 201);
    }

    public function uploadDocument(Request $request, Company $company): JsonResponse
    {
        $this->authorizeApplicant($request, $company);
        $validated = $request->validate(['type' => ['required', Rule::in(['registration', 'tax_clearance', 'operator_licence', 'transport_permit', 'insurance', 'bus_ownership', 'bank_confirmation'])], 'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'], 'expires_at' => ['nullable', 'date', 'after:today']]);
        $path = $validated['document']->store("companies/{$company->id}/documents/operator-applications", ['disk' => 'local']);
        $document = (new PlatformResource)->useModule('company_documents');
        $document = $document->newQuery()->updateOrCreate(['company_id' => $company->id, 'code' => $validated['type']], ['user_id' => $request->user()->id, 'name' => $validated['document']->getClientOriginalName(), 'status' => 'submitted', 'ends_at' => $validated['expires_at'] ?? null, 'data' => ['path' => $path, 'mime_type' => $validated['document']->getMimeType(), 'size' => $validated['document']->getSize()]]);

        return response()->json($document, 201);
    }

    public function submit(Request $request, Company $company): JsonResponse
    {
        $this->authorizeApplicant($request, $company);
        abort_unless(in_array($company->status, ['application_draft', 'information_requested'], true), 409, 'Only draft applications or requested updates can be submitted.');
        $types = (new PlatformResource)->useModule('company_documents')->newQuery()->where('company_id', $company->id)->pluck('code');
        $missing = collect(['registration', 'operator_licence', 'transport_permit', 'insurance', 'bank_confirmation'])->diff($types);
        abort_if($missing->isNotEmpty(), 422, 'Required documents are missing: '.$missing->join(', '));
        $company->update(['status' => 'under_review', 'submitted_at' => now()]);

        return response()->json($company);
    }

    public function decide(Request $request, Company $company): JsonResponse
    {
        abort_unless($request->user()->can('companies.manage'), 403);
        abort_unless($company->status === 'under_review', 409, 'Only submitted applications can be reviewed.');
        $validated = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected', 'information_requested'])], 'reason' => ['nullable', 'required_unless:decision,approved', 'string', 'max:2000'], 'commission_rate' => ['nullable', 'required_if:decision,approved', 'numeric', 'between:0,100']]);
        DB::transaction(function () use ($company, $validated): void {
            $settings = $company->settings ?? [];
            $settings['review'] = ['decision' => $validated['decision'], 'reason' => $validated['reason'] ?? null, 'reviewed_at' => now()->toIso8601String()];
            if ($validated['decision'] === 'approved') {
                $settings['commission_rate'] = $validated['commission_rate'];
            }
            $company->update(['status' => $validated['decision'] === 'approved' ? 'active' : $validated['decision'], 'settings' => $settings, 'verified_at' => $validated['decision'] === 'approved' ? now() : null]);
            if ($validated['decision'] === 'approved') {
                User::where('company_id', $company->id)->where('role', 'operator_applicant')->get()->each(function (User $user): void {
                    $user->forceFill(['role' => 'company_owner'])->save();
                    $user->syncRoles('company_owner');
                });
            }
        });

        return response()->json($company->refresh());
    }

    private function authorizeApplicant(Request $request, Company $company): void
    {
        abort_unless($request->user()->company_id === $company->id && $request->user()->role === 'operator_applicant', 404);
    }
}
