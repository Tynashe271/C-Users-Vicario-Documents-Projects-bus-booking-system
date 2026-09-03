<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Notifications\PhoneVerificationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PhoneVerificationController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        abort_unless($request->user()->phone, 422, 'Add a phone number before requesting verification.');
        $code = (string) random_int(100000, 999999);
        $request->user()->forceFill(['phone_verification_code' => Hash::make($code), 'phone_verification_expires_at' => now()->addMinutes(10), 'phone_verification_attempts' => 0])->save();
        $request->user()->notify((new PhoneVerificationCode($code))->afterCommit());

        return response()->json(['message' => 'Phone verification code sent.']);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();
        if (! $user->phone_verification_code || ! $user->phone_verification_expires_at?->isFuture() || $user->phone_verification_attempts >= 5 || ! Hash::check($validated['code'], $user->phone_verification_code)) {
            $user->increment('phone_verification_attempts');
            throw ValidationException::withMessages(['code' => 'The phone verification code is invalid or expired.']);
        }
        $user->forceFill(['phone_verified_at' => now(), 'phone_verification_code' => null, 'phone_verification_expires_at' => null, 'phone_verification_attempts' => 0])->save();

        return response()->json(['message' => 'Phone number verified.']);
    }
}
