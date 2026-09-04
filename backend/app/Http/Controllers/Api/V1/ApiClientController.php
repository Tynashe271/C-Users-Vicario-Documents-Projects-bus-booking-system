<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ApiClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ApiClient::query()->select(['id', 'company_id', 'user_id', 'client_id', 'name', 'status', 'data', 'last_used_at', 'expires_at', 'revoked_at', 'created_at']);
        if ($this->canManagePlatformWide($request)) {
            // Platform security staff see every key; everyone else only their own company's.
        } else {
            $query->where('company_id', $this->ownCompanyId($request));
        }

        return response()->json($query->latest()->paginate(25));
    }

    public function show(Request $request, ApiClient $apiClient): JsonResponse
    {
        $this->authorizeClient($request, $apiClient);

        return response()->json($apiClient);
    }

    /**
     * Creates the key's service account (a real User with a normal company role, so every
     * existing permission check just works against it) and the ApiClient record together. The raw
     * secret is only ever returned here — only its hash is stored, so it cannot be recovered later.
     *
     * A company_id-less (platform-wide) key requires genuine platform security clearance
     * (security.manage). Company management staff (companies.manage — company_owner/
     * company_administrator) may provision keys, but only ever scoped to their own company.
     */
    public function store(Request $request): JsonResponse
    {
        $canManagePlatformWide = $this->canManagePlatformWide($request);
        abort_unless($canManagePlatformWide || $request->user()->can('companies.manage'), 403);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'company_id' => [$canManagePlatformWide ? 'nullable' : 'prohibited', 'integer', Rule::exists('companies', 'id')],
            'role' => ['required', Rule::in(config('platform.company_roles'))],
            'abilities' => ['nullable', 'array'], 'abilities.*' => ['string', 'max:100'],
            'allowed_ips' => ['nullable', 'array'], 'allowed_ips.*' => ['ip'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $companyId = $canManagePlatformWide ? ($validated['company_id'] ?? null) : $this->ownCompanyId($request);

        $result = DB::transaction(function () use ($request, $validated, $companyId): array {
            $clientId = 'AK'.strtoupper(Str::random(12));
            $secret = Str::random(40);
            $company = $companyId ? Company::find($companyId) : null;
            $serviceUser = User::create([
                'name' => $validated['name'].' (API)',
                'email' => 'api-'.strtolower($clientId).'@'.($company?->slug ?? 'platform').'.apiclient.internal',
                'password' => Str::random(40),
                'company_id' => $companyId,
                'role' => $validated['role'],
            ]);
            $serviceUser->assignRole($validated['role']);
            $apiClient = ApiClient::create([
                'company_id' => $companyId, 'user_id' => $serviceUser->id, 'code' => $clientId, 'name' => $validated['name'], 'status' => 'active',
                'client_id' => $clientId, 'key_hash' => hash('sha256', $secret), 'expires_at' => $validated['expires_at'] ?? null,
                'data' => ['abilities' => $validated['abilities'] ?? ['*'], 'allowed_ips' => $validated['allowed_ips'] ?? [], 'created_by' => $request->user()->id],
            ]);

            return ['client' => $apiClient, 'api_key' => "{$clientId}.{$secret}"];
        });

        return response()->json($result, 201);
    }

    public function rotate(Request $request, ApiClient $apiClient): JsonResponse
    {
        $this->authorizeClient($request, $apiClient);
        abort_unless($apiClient->isUsable(), 409, 'A revoked or expired key cannot be rotated; issue a new one instead.');
        $secret = Str::random(40);
        $apiClient->update(['key_hash' => hash('sha256', $secret)]);

        return response()->json(['client' => $apiClient->refresh(), 'api_key' => "{$apiClient->client_id}.{$secret}"]);
    }

    public function revoke(Request $request, ApiClient $apiClient): JsonResponse
    {
        $this->authorizeClient($request, $apiClient);
        abort_unless($apiClient->isUsable(), 409, 'This key was already revoked or has expired.');
        $apiClient->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $request->user()->id]);
        $apiClient->user?->forceFill(['status' => 'suspended'])->save();

        return response()->json($apiClient->refresh());
    }

    /** True platform security clearance: can see/manage any company's keys, or issue a company_id-less one. */
    private function canManagePlatformWide(Request $request): bool
    {
        return in_array($request->user()->role, config('platform.platform_roles'), true) && $request->user()->can('security.manage');
    }

    private function ownCompanyId(Request $request): int
    {
        abort_unless($request->user()->company_id && $request->user()->can('companies.manage'), 403);

        return $request->user()->company_id;
    }

    private function authorizeClient(Request $request, ApiClient $apiClient): void
    {
        if ($this->canManagePlatformWide($request)) {
            return;
        }
        abort_unless($apiClient->company_id === $this->ownCompanyId($request), 404);
    }
}
