<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlatformStaffController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('security.manage'), 403);
        $allowedRoles = array_values(array_diff(config('platform.platform_roles'), ['super_administrator']));
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:12'],
            'role' => ['required', Rule::in($allowedRoles)],
        ]);
        $user = User::create($validated + ['company_id' => null]);
        $user->assignRole($validated['role']);
        $this->audit($request, $user, 'created', null, $user->status ?? 'active', 'Platform staff account created');

        return response()->json($user->only(['id', 'name', 'email', 'phone', 'role', 'status', 'created_at']), 201);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('security.manage'), 403);
        abort_if($user->role === 'super_administrator' || $user->is($request->user()), 422, 'This protected account cannot be changed here.');
        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended', 'temporarily_locked', 'deactivated'])],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $previousStatus = $user->status;
        $user->forceFill(['status' => $validated['status']])->save();
        $this->audit($request, $user, 'status.changed', $previousStatus, $validated['status'], $validated['reason']);

        return response()->json($user->only(['id', 'name', 'email', 'phone', 'role', 'status', 'created_at']));
    }

    private function audit(Request $request, User $user, string $event, ?string $previousStatus, string $status, string $reason): void
    {
        (new PlatformResource)->useModule('audit_logs')->fill([
            'company_id' => $user->company_id,
            'user_id' => $request->user()->id,
            'code' => "user.{$event}.".Str::uuid(),
            'name' => "User {$event}",
            'status' => 'recorded',
            'data' => ['record_type' => User::class, 'record_id' => $user->id, 'previous' => ['status' => $previousStatus], 'new' => ['status' => $status], 'reason' => $reason, 'ip_address' => $request->ip()],
        ])->save();
    }
}
