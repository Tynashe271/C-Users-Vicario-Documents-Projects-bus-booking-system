<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class StaffInvitationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->company_id && $request->user()->can('agents.manage'), 403);
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'branch_id' => ['nullable', 'integer'],
            'role' => ['required', 'string', 'in:'.implode(',', config('platform.company_roles'))],
            'expires_in_days' => ['sometimes', 'integer', 'between:1,30'],
        ]);
        if (isset($validated['branch_id'])) {
            abort_unless(Branch::whereKey($validated['branch_id'])->where('company_id', $request->user()->company_id)->exists(), 404);
        }
        $token = Str::random(64);
        $invitation = StaffInvitation::create([
            'company_id' => $request->user()->company_id,
            'branch_id' => $validated['branch_id'] ?? null,
            'invited_by' => $request->user()->id,
            'email' => mb_strtolower($validated['email']),
            'role' => $validated['role'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays($validated['expires_in_days'] ?? 7),
        ]);

        return response()->json(['data' => $invitation, 'invitation_token' => app()->isProduction() ? null : $token], 201);
    }

    public function accept(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30', 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
        ]);
        $invitation = StaffInvitation::where('token_hash', hash('sha256', $validated['token']))->whereNull('accepted_at')->where('expires_at', '>', now())->firstOrFail();
        $user = DB::transaction(function () use ($invitation, $validated): User {
            $user = User::create([
                'company_id' => $invitation->company_id,
                'branch_id' => $invitation->branch_id,
                'name' => $validated['name'],
                'email' => $invitation->email,
                'phone' => $validated['phone'] ?? null,
                'password' => $validated['password'],
                'role' => $invitation->role,
            ]);
            $user->assignRole($invitation->role);
            $invitation->update(['accepted_at' => now()]);

            return $user;
        });

        return response()->json(['data' => $user], 201);
    }
}
