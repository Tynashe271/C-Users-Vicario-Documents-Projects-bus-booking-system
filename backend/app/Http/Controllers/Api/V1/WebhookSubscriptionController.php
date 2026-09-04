<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Lets a company register a partner URL to receive signed HTTP callbacks when events named in
 * config('webhooks.events') happen — see WebhookDispatcher for who actually fires those, and
 * DeliverWebhookNotification for delivery/signing/retries. Mirrors ApiClientController: the raw
 * secret is only ever returned once, on creation or rotation.
 */
class WebhookSubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->query($request)->latest()->paginate(25)->through(fn (PlatformResource $subscription) => $this->redacted($subscription)));
    }

    public function show(Request $request, int $webhook): JsonResponse
    {
        return response()->json($this->redacted($this->authorized($request, $webhook)));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->ownCompanyId($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1'], 'events.*' => [Rule::in(config('webhooks.events'))],
        ]);
        $secret = Str::random(40);
        $subscription = (new PlatformResource)->useModule('webhook_subscriptions');
        $subscription->fill([
            'company_id' => $companyId, 'user_id' => $request->user()->id, 'code' => 'wh-'.Str::random(10), 'name' => $validated['name'], 'status' => 'active',
            'data' => ['url' => $validated['url'], 'events' => $validated['events'], 'secret' => $secret],
        ])->save();

        return response()->json(['subscription' => $this->redacted($subscription), 'secret' => $secret], 201);
    }

    public function update(Request $request, int $webhook): JsonResponse
    {
        $subscription = $this->authorized($request, $webhook);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'url' => ['sometimes', 'url', 'max:2048'],
            'events' => ['sometimes', 'array', 'min:1'], 'events.*' => [Rule::in(config('webhooks.events'))],
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
        ]);
        $data = array_merge($subscription->data ?? [], array_intersect_key($validated, array_flip(['url', 'events'])));
        $subscription->update([
            'name' => $validated['name'] ?? $subscription->name,
            'status' => $validated['status'] ?? $subscription->status,
            'data' => $data,
        ]);

        return response()->json($this->redacted($subscription->refresh()));
    }

    public function rotate(Request $request, int $webhook): JsonResponse
    {
        $subscription = $this->authorized($request, $webhook);
        $secret = Str::random(40);
        $subscription->update(['data' => [...($subscription->data ?? []), 'secret' => $secret]]);

        return response()->json(['subscription' => $this->redacted($subscription->refresh()), 'secret' => $secret]);
    }

    public function destroy(Request $request, int $webhook): JsonResponse
    {
        $this->authorized($request, $webhook)->delete();

        return response()->json(status: 204);
    }

    /** The signing secret is only ever handed back on creation or rotation — see store()/rotate(). */
    private function redacted(PlatformResource $subscription): PlatformResource
    {
        $clone = clone $subscription;
        $clone->data = array_diff_key($subscription->data ?? [], ['secret' => true]);

        return $clone;
    }

    private function query(Request $request)
    {
        $query = (new PlatformResource)->useModule('webhook_subscriptions')->newQuery();
        if ($this->canManagePlatformWide($request)) {
            return $query;
        }

        return $query->where('company_id', $this->ownCompanyId($request));
    }

    private function authorized(Request $request, int $id): PlatformResource
    {
        return $this->query($request)->findOrFail($id);
    }

    private function canManagePlatformWide(Request $request): bool
    {
        return in_array($request->user()->role, config('platform.platform_roles'), true) && $request->user()->can('security.manage');
    }

    private function ownCompanyId(Request $request): int
    {
        abort_unless($request->user()->company_id && $request->user()->can('companies.manage'), 403);

        return $request->user()->company_id;
    }
}
