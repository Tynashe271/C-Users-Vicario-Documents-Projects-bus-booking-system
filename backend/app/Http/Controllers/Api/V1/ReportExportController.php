<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportExport;
use App\Models\PlatformResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kicks off a background CSV export of a company's (or, for platform staff, the platform's)
 * bookings/commissions/settlements — see GenerateReportExport for how it's actually rendered.
 * The request returns immediately with a "queued" record; the caller polls show() for status.
 */
class ReportExportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->query($request)->latest()->paginate(25));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['bookings', 'commissions', 'settlements'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $companyId = $this->isPlatformUser($request) ? null : $this->ownCompanyId($request);
        $export = (new PlatformResource)->useModule('report_exports');
        $export->fill([
            'company_id' => $companyId, 'user_id' => $request->user()->id, 'code' => 'export-'.now()->format('YmdHisu'), 'name' => str($validated['type'])->headline().' export', 'status' => 'queued',
            'data' => ['type' => $validated['type'], 'from' => $validated['from'] ?? null, 'to' => $validated['to'] ?? null],
        ])->save();
        GenerateReportExport::dispatch($export->id);

        return response()->json($export, 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($this->authorized($request, $id));
    }

    public function download(Request $request, int $id): StreamedResponse
    {
        $export = $this->authorized($request, $id);
        abort_unless($export->status === 'ready', 409, 'This export is not ready yet.');
        $path = data_get($export->data, 'path');
        abort_unless($path && Storage::exists($path), 404, 'The exported file is no longer available.');

        return Storage::download($path, "{$export->name}.csv");
    }

    private function query(Request $request)
    {
        $query = (new PlatformResource)->useModule('report_exports')->newQuery();
        if ($this->isPlatformUser($request)) {
            return $query;
        }

        return $query->where('company_id', $this->ownCompanyId($request));
    }

    private function authorized(Request $request, int $id): PlatformResource
    {
        return $this->query($request)->findOrFail($id);
    }

    private function isPlatformUser(Request $request): bool
    {
        return in_array($request->user()->role, config('platform.platform_roles'), true) && $request->user()->can('reports.view');
    }

    private function ownCompanyId(Request $request): int
    {
        abort_unless($request->user()->company_id && $request->user()->can('reports.view'), 403);

        return $request->user()->company_id;
    }
}
