<?php

namespace App\Services;

use App\Models\PlatformResource;
use App\Models\User;
use Illuminate\Http\Request;

class AuthenticationTokenService
{
    /** @return array{user:User, token:string} */
    public function issue(User $user, string $deviceName, Request $request): array
    {
        $user->forceFill(['last_login_at' => now()])->save();
        $deviceCode = hash('sha256', implode('|', [$user->id, $deviceName, $request->userAgent() ?? 'unknown']));
        $device = (new PlatformResource)->useModule('login_devices')->newQuery()->firstOrNew([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'code' => $deviceCode,
        ]);
        $device->fill([
            'name' => $deviceName,
            'status' => 'active',
            'data' => ['ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'last_seen_at' => now()->toIso8601String()],
        ])->save();

        return ['user' => $user->load('roles.permissions'), 'token' => $user->createToken($deviceName)->plainTextToken];
    }
}
