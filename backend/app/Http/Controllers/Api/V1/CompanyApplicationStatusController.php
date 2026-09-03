<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyApplicationComment;
use App\Models\PlatformResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyApplicationStatusController extends Controller
{
    public function show(Request $request, Company $company): JsonResponse
    {
        $this->authorizeAccess($request, $company);

        return response()->json([
            'application' => $company,
            'documents' => (new PlatformResource)->useModule('company_documents')->newQuery()->where('company_id', $company->id)->latest()->get(),
            'comments' => CompanyApplicationComment::where('company_id', $company->id)->with('user:id,name,role')->latest()->get(),
        ]);
    }

    public function comment(Request $request, Company $company): JsonResponse
    {
        $this->authorizeAccess($request, $company);
        $validated = $request->validate(['comment' => ['required', 'string', 'max:2000'], 'requires_response' => ['sometimes', 'boolean']]);
        $comment = CompanyApplicationComment::create([...$validated, 'company_id' => $company->id, 'user_id' => $request->user()->id]);

        return response()->json(['data' => $comment->load('user:id,name,role')], 201);
    }

    public function reviewDocument(Request $request, Company $company, int $document): JsonResponse
    {
        abort_unless($request->user()->can('companies.manage'), 403);
        $validated = $request->validate(['status' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => ['nullable', 'required_if:status,rejected', 'string', 'max:1000']]);
        $record = (new PlatformResource)->useModule('company_documents')->newQuery()->where('company_id', $company->id)->findOrFail($document);
        $data = $record->data ?? [];
        $data['review'] = ['reviewed_by' => $request->user()->id, 'reason' => $validated['reason'] ?? null, 'reviewed_at' => now()->toIso8601String()];
        $record->update(['status' => $validated['status'], 'data' => $data]);

        return response()->json(['data' => $record->refresh()]);
    }

    private function authorizeAccess(Request $request, Company $company): void
    {
        abort_unless($request->user()->company_id === $company->id || $request->user()->can('companies.manage'), 404);
    }
}
