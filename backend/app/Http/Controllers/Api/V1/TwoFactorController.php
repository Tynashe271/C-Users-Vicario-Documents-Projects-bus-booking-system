<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthenticationTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    public function setup(Request $request, Google2FA $google2FA): JsonResponse
    {
        $secret = $google2FA->generateSecretKey();
        $recoveryCodes = collect(range(1, 8))->map(fn (): string => Str::upper(Str::random(10)))->all();
        $request->user()->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => array_map(fn (string $code): string => Hash::make($code), $recoveryCodes), 'two_factor_confirmed_at' => null])->save();

        return response()->json(['secret' => $secret, 'otpauth_url' => $google2FA->getQRCodeUrl(config('app.name'), $request->user()->email, $secret), 'recovery_codes' => $recoveryCodes]);
    }

    public function confirm(Request $request, Google2FA $google2FA): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'digits:6']]);
        abort_unless($request->user()->two_factor_secret, 409, 'Two-factor setup has not started.');
        if (! $google2FA->verifyKey($request->user()->two_factor_secret, $validated['code'])) {
            throw ValidationException::withMessages(['code' => 'The two-factor code is invalid.']);
        }
        $request->user()->forceFill(['two_factor_confirmed_at' => now()])->save();

        return response()->json(['message' => 'Two-factor authentication enabled.']);
    }

    public function challenge(Request $request, Google2FA $google2FA, AuthenticationTokenService $tokens): JsonResponse
    {
        $validated = $request->validate(['challenge_token' => ['required', 'string', 'size:64'], 'code' => ['required', 'string', 'max:20']]);
        $cacheKey = 'two-factor-login:'.hash('sha256', $validated['challenge_token']);
        $challenge = Cache::get($cacheKey);
        if (! $challenge || ! $user = User::find($challenge['user_id'])) {
            throw ValidationException::withMessages(['challenge_token' => 'The login challenge is invalid or expired.']);
        }
        $validTotp = preg_match('/^\d{6}$/', $validated['code']) === 1 && $google2FA->verifyKey($user->two_factor_secret, $validated['code']);
        $recoveryCodes = $user->two_factor_recovery_codes ?? [];
        $recoveryIndex = collect($recoveryCodes)->search(fn (string $hash): bool => Hash::check(Str::upper($validated['code']), $hash));
        if (! $validTotp && $recoveryIndex === false) {
            throw ValidationException::withMessages(['code' => 'The two-factor code is invalid.']);
        }
        if ($recoveryIndex !== false) {
            unset($recoveryCodes[$recoveryIndex]);
            $user->forceFill(['two_factor_recovery_codes' => array_values($recoveryCodes)])->save();
        }
        Cache::forget($cacheKey);

        return response()->json($tokens->issue($user, $challenge['device_name'], $request));
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate(['password' => ['required', 'current_password']]);
        $request->user()->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }
}
