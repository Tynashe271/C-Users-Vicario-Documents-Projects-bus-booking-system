<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformResource;
use App\Services\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LoyaltyController extends Controller
{
    public function show(Request $request, LoyaltyService $loyalty): JsonResponse
    {
        $account = $loyalty->account($request->user());

        $rewards = collect(['promotions', 'vouchers'])->flatMap(fn (string $module) => (new PlatformResource)->useModule($module)->newQuery()->where('status', 'active')->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))->get()->map(fn ($reward) => [...$reward->toArray(), 'reward_type' => $module]));

        return response()->json(['account' => $account, 'transactions' => $account->transactions()->latest()->limit(50)->get(), 'rewards' => $rewards, 'expiring_rewards' => $rewards->whereNotNull('ends_at')->sortBy('ends_at')->take(10)->values(), 'redemption_rate' => ['points' => 100, 'discount' => 1], 'levels' => ['Bronze' => 0, 'Silver' => 500, 'Gold' => 1500, 'Platinum' => 5000]]);
    }

    public function redeem(Request $request, LoyaltyService $loyalty): JsonResponse
    {
        $validated = $request->validate(['points' => ['required', 'integer', 'min:100']]);
        $coupon = $loyalty->redeem($request->user(), $validated['points']);

        return response()->json(['coupon_code' => $coupon->code, 'discount' => (float) $coupon->amount, 'account' => $request->user()->loyaltyAccount()->first()], 201);
    }

    public function claim(Request $request, LoyaltyService $loyalty): JsonResponse
    {
        $validated = $request->validate(['type' => ['required', Rule::in(['referral', 'promotion', 'route', 'operator', 'voucher'])], 'code' => ['required', 'string', 'max:100']]);

        return response()->json(['account' => $loyalty->claimReward($request->user(), $validated['type'], $validated['code'])]);
    }

    public function update(Request $request, LoyaltyService $loyalty): JsonResponse
    {
        $validated = $request->validate(['expiry_alerts_enabled' => ['required', 'boolean']]);
        $account = $loyalty->account($request->user());
        $account->update($validated);

        return response()->json(['account' => $account->refresh()]);
    }
}
