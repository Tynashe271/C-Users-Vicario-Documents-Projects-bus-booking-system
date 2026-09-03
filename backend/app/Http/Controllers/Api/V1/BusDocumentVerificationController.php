<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusDocumentVerificationController extends Controller
{
    public function __invoke(Request $request, BusDocument $document): JsonResponse
    {
        abort_unless($request->user()->can('companies.manage') && in_array($request->user()->role, config('platform.platform_roles'), true), 403);
        $validated = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => ['nullable', 'required_if:decision,rejected', 'string', 'max:2000']]);
        $document->update(['status' => $validated['decision'], 'verified_by' => $request->user()->id, 'verified_at' => now(), 'rejection_reason' => $validated['reason'] ?? null]);

        return response()->json(['data' => $document->refresh()]);
    }
}
