<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PlatformResource;
use App\Models\Settlement;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminCompanyController extends Controller
{
    public function show(Request $request, Company $company): JsonResponse
    {
        abort_unless($request->user()->can('companies.manage'), 403);

        return response()->json([
            'company' => $company,
            'owner' => $company->users()->where('role', 'company_owner')->first(['id', 'name', 'email', 'phone', 'status']),
            'counts' => [
                'branches' => (new PlatformResource)->useModule('branches')->newQuery()->where('company_id', $company->id)->count(),
                'staff' => Employee::where('company_id', $company->id)->count(),
                'buses' => Bus::where('company_id', $company->id)->count(),
                'trips' => Trip::where('company_id', $company->id)->count(),
                'bookings' => Booking::where('company_id', $company->id)->count(),
            ],
            'revenue' => (float) Booking::where('company_id', $company->id)->whereIn('status', ['confirmed', 'completed'])->sum('total'),
            'settlements' => Settlement::where('company_id', $company->id)->latest('created_at')->limit(10)->get(),
            'documents' => (new PlatformResource)->useModule('company_documents')->newQuery()->where('company_id', $company->id)->latest()->get(),
            'complaints' => (new PlatformResource)->useModule('support_cases')->newQuery()->where('company_id', $company->id)->latest()->limit(10)->get(),
            'ratings' => (new PlatformResource)->useModule('reviews')->newQuery()->where('company_id', $company->id)->latest()->limit(10)->get(),
        ]);
    }

    public function updateStatus(Request $request, Company $company): JsonResponse
    {
        abort_unless($request->user()->can('companies.manage'), 403);
        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended', 'closed'])],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $previousStatus = $company->status;
        $company->update([
            'status' => $validated['status'],
            'suspended_at' => $validated['status'] === 'suspended' ? now() : null,
            'closed_at' => $validated['status'] === 'closed' ? now() : null,
        ]);
        $audit = (new PlatformResource)->useModule('audit_logs');
        $audit->fill([
            'company_id' => $company->id,
            'user_id' => $request->user()->id,
            'code' => 'company.status.changed.'.Str::uuid(),
            'name' => "Company status changed from {$previousStatus} to {$validated['status']}",
            'status' => 'recorded',
            'data' => ['record_type' => Company::class, 'record_id' => $company->id, 'previous' => ['status' => $previousStatus], 'new' => ['status' => $validated['status']], 'reason' => $validated['reason'], 'ip_address' => $request->ip()],
        ])->save();

        return response()->json($company->refresh());
    }
}
