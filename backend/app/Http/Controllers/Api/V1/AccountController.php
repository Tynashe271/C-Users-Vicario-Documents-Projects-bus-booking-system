<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformResource;
use App\Models\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', Rule::unique('users')->ignore($user)],
            'language' => ['sometimes', 'string', 'max:10'], 'currency' => ['sometimes', 'string', 'size:3'], 'timezone' => ['sometimes', 'timezone'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
        ]);
        if (isset($validated['email']) && $validated['email'] !== $user->email) {
            $validated['email_verified_at'] = null;
        }
        if (array_key_exists('phone', $validated) && $validated['phone'] !== $user->phone) {
            $validated['phone_verified_at'] = null;
        }
        $user->forceFill($validated)->save();

        return response()->json($user->refresh());
    }

    public function export(Request $request): JsonResponse
    {
        $modules = ['profiles', 'saved_passengers', 'saved_routes', 'saved_payment_methods', 'notification_preferences', 'login_devices', 'account_requests', 'recent_searches', 'emergency_contacts', 'travel_documents', 'notifications', 'reviews', 'support_cases', 'support_messages', 'wallets', 'wallet_transactions', 'loyalty_accounts', 'loyalty_transactions', 'referrals', 'waitlists', 'tracking_links', 'lost_properties'];
        $data = collect($modules)->mapWithKeys(fn (string $module): array => [$module => (new PlatformResource)->useModule($module)->newQuery()->where('user_id', $request->user()->id)->get()]);

        return response()->json(['generated_at' => now()->toIso8601String(), 'user' => $request->user(), 'data' => $data]);
    }

    public function updateProfilePhoto(Request $request): JsonResponse
    {
        $validated = $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        $user = $request->user();

        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
        }

        $path = $validated['photo']->store('profile-photos', 'public');
        $user->forceFill(['profile_photo_path' => $path])->save();

        return response()->json(['profile_photo_url' => Storage::disk('public')->url($path)]);
    }

    public function devices(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()->latest()->get()->map(fn ($token): array => [
            'id' => $token->id,
            'name' => $token->name,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
            'current' => $request->user()->currentAccessToken()?->id === $token->id,
        ]);

        return response()->json(['data' => $tokens]);
    }

    public function loginHistory(Request $request): JsonResponse
    {
        $history = SecurityAudit::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('event', ['login_succeeded', 'login_failed', 'login_blocked'])
            ->latest('id')
            ->limit(50)
            ->get(['id', 'event', 'ip_address', 'user_agent', 'created_at']);

        return response()->json(['data' => $history]);
    }

    public function revokeDevice(Request $request, int $tokenId): JsonResponse
    {
        $request->user()->tokens()->whereKey($tokenId)->firstOrFail()->delete();

        return response()->json(['message' => 'The selected session has been signed out.']);
    }

    public function deactivate(Request $request): JsonResponse
    {
        $validated = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();

        if (! Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'The supplied password is incorrect.'], 422);
        }

        $user->forceFill(['status' => 'inactive', 'deactivated_at' => now()])->save();
        $user->tokens()->delete();

        return response()->json(['message' => 'Your account has been deactivated.']);
    }

    public function requestDeletion(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $user = $request->user();
        $record = (new PlatformResource)->useModule('account_requests');
        $record->fill(['user_id' => $user->id, 'company_id' => $user->company_id, 'code' => 'delete-account', 'name' => 'Account deletion request', 'status' => 'pending', 'starts_at' => now(), 'ends_at' => now()->addDays(30), 'data' => ['requested_ip' => $request->ip()]])->save();
        $user->forceFill(['status' => 'pending_deletion', 'deletion_requested_at' => now()])->save();
        $user->tokens()->delete();

        return response()->json(['message' => 'Account deletion scheduled after the 30-day recovery period.'], 202);
    }

    public function revokeDevices(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();
        (new PlatformResource)->useModule('login_devices')->newQuery()->where('user_id', $request->user()->id)->update(['status' => 'revoked']);

        return response()->json(['message' => 'All devices have been signed out.']);
    }
}
