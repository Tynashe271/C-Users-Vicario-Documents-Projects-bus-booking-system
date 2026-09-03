<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformResource;
use App\Models\SecurityAudit;
use App\Models\User;
use App\Services\AuthenticationTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, AuthenticationTokenService $tokens): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30', 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
            'device_name' => ['required', 'string', 'max:100'],
            'terms_accepted' => ['required', 'accepted'],
        ]);
        $user = User::create($validated + ['role' => 'passenger']);
        $user->assignRole('passenger');
        $acceptance = (new PlatformResource)->useModule('terms_acceptances');
        $acceptance->fill([
            'user_id' => $user->id,
            'code' => 'platform-terms-and-privacy',
            'name' => 'Platform terms and privacy policy',
            'status' => 'accepted',
            'data' => ['accepted_at' => now()->toIso8601String(), 'ip_address' => $request->ip()],
        ])->save();
        $user->sendEmailVerificationNotification();

        return response()->json($tokens->issue($user, $validated['device_name'], $request), 201);
    }

    public function login(Request $request, AuthenticationTokenService $tokens): JsonResponse
    {
        $validated = $request->validate(['login' => ['required_without:email', 'string', 'max:255'], 'email' => ['required_without:login', 'nullable', 'string', 'max:255'], 'password' => ['required', 'string'], 'device_name' => ['required', 'string', 'max:100']]);
        $identifier = $validated['login'] ?? $validated['email'];
        $user = User::query()->where('email', $identifier)->orWhere('phone', $identifier)->first();
        if ($user?->locked_until?->isFuture()) {
            $this->auditLogin($request, $user, $identifier, 'login_blocked');
            throw ValidationException::withMessages(['login' => 'This account is temporarily locked. Try again later.']);
        }
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            if ($user) {
                $attempts = $user->failed_login_attempts + 1;
                $user->forceFill(['failed_login_attempts' => $attempts, 'locked_until' => $attempts >= 5 ? now()->addMinutes(15) : null])->save();
            }
            $this->auditLogin($request, $user, $identifier, 'login_failed');
            throw ValidationException::withMessages(['login' => 'The supplied credentials are invalid.']);
        }
        if ($user->status !== 'active') {
            $this->auditLogin($request, $user, $identifier, 'login_blocked');
            throw ValidationException::withMessages(['login' => 'This account is not active.']);
        }
        $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->save();
        $this->auditLogin($request, $user, $identifier, 'login_succeeded');
        if ($user->two_factor_confirmed_at) {
            $challenge = Str::random(64);
            Cache::put('two-factor-login:'.hash('sha256', $challenge), ['user_id' => $user->id, 'device_name' => $validated['device_name']], now()->addMinutes(5));

            return response()->json(['two_factor_required' => true, 'challenge_token' => $challenge], 202);
        }

        return response()->json($tokens->issue($user, $validated['device_name'], $request));
    }

    private function auditLogin(Request $request, ?User $user, string $identifier, string $event): void
    {
        SecurityAudit::create([
            'user_id' => $user?->id,
            'event' => $event,
            'identifier' => hash('sha256', mb_strtolower($identifier)),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->load('roles.permissions'));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
