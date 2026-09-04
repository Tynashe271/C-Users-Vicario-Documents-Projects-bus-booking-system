<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Models\PlatformResource;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates third-party partner requests carrying an `X-Api-Key: <client_id>.<secret>` header,
 * as an alternative to a logged-in Sanctum token — for server-to-server integrations that aren't a
 * person at a device. On success the key's linked service-account User becomes the request's
 * authenticated user, so every existing controller's `$request->user()->company_id` /
 * `$request->user()->can(...)` check works against it exactly as it would for a real staff login.
 *
 * Register a required scope per route with `apikey:<ability>` (defaults to any ability if omitted).
 */
class AuthenticateApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $ability = '*'): Response
    {
        $header = (string) $request->header('X-Api-Key', '');
        abort_if($header === '', 401, 'Missing API key.');
        [$clientId, $secret] = array_pad(explode('.', $header, 2), 2, null);
        abort_if(blank($clientId) || blank($secret), 401, 'Malformed API key.');

        $client = ApiClient::where('client_id', $clientId)->first();
        abort_if(! $client || ! is_string($client->key_hash) || ! hash_equals($client->key_hash, hash('sha256', $secret)), 401, 'Invalid API key.');
        abort_unless($client->isUsable(), 401, 'This API key is inactive, revoked, or expired.');

        $allowedIps = $client->allowedIps();
        abort_if($allowedIps !== [] && ! in_array($request->ip(), $allowedIps, true), 403, 'This IP address is not permitted to use this API key.');
        abort_unless($ability === '*' || $client->hasAbility($ability), 403, "This API key does not have the '{$ability}' scope.");

        $user = $client->user;
        abort_unless($user && $user->status === 'active', 401, 'This API key is not linked to an active account.');

        $client->update(['last_used_at' => now()]);
        $this->recordUsage($client);
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    /** One row per (key, calendar day), incremented per request — see ApiClientController::usage(). */
    private function recordUsage(ApiClient $client): void
    {
        $code = $client->client_id.':'.now()->toDateString();
        $module = (new PlatformResource)->useModule('api_usage_records');
        $record = $module->newQuery()->where('code', $code)->first();
        if ($record) {
            $record->increment('amount');

            return;
        }
        $module->fill(['company_id' => $client->company_id, 'user_id' => $client->user_id, 'code' => $code, 'name' => 'API usage: '.$client->client_id, 'status' => 'recorded', 'amount' => 1])->save();
    }
}
