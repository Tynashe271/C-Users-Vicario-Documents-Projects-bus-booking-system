<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AuthenticationTokenService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SocialAuthenticationController extends Controller
{
    public function __invoke(Request $request, string $provider, AuthenticationTokenService $tokens): JsonResponse
    {
        $request->merge(['provider' => $provider]);
        $validated = $request->validate(['provider' => ['required', Rule::in(array_keys(config('services.social.providers', [])))], 'access_token' => ['required', 'string', 'max:4096'], 'device_name' => ['required', 'string', 'max:100']]);
        $providerConfig = config("services.social.providers.$provider");
        if (blank($providerConfig['userinfo_url'] ?? null)) {
            throw ValidationException::withMessages(['provider' => 'This social provider is not configured.']);
        }
        try {
            $response = Http::acceptJson()->withToken($validated['access_token'])->timeout(10)->retry(2, 200)->get($providerConfig['userinfo_url']);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['access_token' => 'The social provider is temporarily unavailable.']);
        }
        if ($response->failed()) {
            throw ValidationException::withMessages(['access_token' => 'The provider rejected this access token.']);
        }
        $profile = $response->json();
        $providerId = data_get($profile, $providerConfig['id_field']);
        $email = data_get($profile, $providerConfig['email_field']);
        if (! is_string($providerId) || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['access_token' => 'The provider did not return a verified identity.']);
        }
        $user = DB::transaction(function () use ($provider, $providerId, $email, $profile, $validated): User {
            $account = SocialAccount::where(['provider' => $provider, 'provider_user_id' => $providerId])->lockForUpdate()->first();
            if ($account) {
                $account->update(['access_token' => $validated['access_token']]);

                return $account->user;
            }
            $user = User::firstOrCreate(['email' => Str::lower($email)], ['name' => data_get($profile, 'name', Str::before($email, '@')), 'password' => Str::password(40), 'role' => 'passenger', 'email_verified_at' => now()]);
            if (! $user->hasRole('passenger')) {
                $user->assignRole('passenger');
            }
            SocialAccount::create(['user_id' => $user->id, 'provider' => $provider, 'provider_user_id' => $providerId, 'access_token' => $validated['access_token']]);

            return $user;
        });

        return response()->json($tokens->issue($user, $validated['device_name'], $request));
    }
}
