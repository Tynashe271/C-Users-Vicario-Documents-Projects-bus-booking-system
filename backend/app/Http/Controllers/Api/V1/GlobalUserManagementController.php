<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GlobalUserManagementController extends Controller
{
    /**
     * Suspend or reactivate any account platform-wide (passenger, agent, driver, company staff —
     * not just platform staff, which PlatformStaffController::updateStatus already covers, and not
     * just a company's own users, which RoleManagementController::updateStatus already covers).
     */
    public function updateStatus(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('security.manage'), 403);
        abort_if($user->is($request->user()), 422, 'You cannot change your own account status.');
        abort_if($user->role === 'super_administrator', 422, 'This protected account cannot be changed here.');
        $validated = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])], 'reason' => ['required', 'string', 'max:2000']]);
        $previousStatus = $user->status;
        $user->forceFill(['status' => $validated['status']])->save();
        if ($validated['status'] === 'suspended') {
            $user->tokens()->delete();
            (new PlatformResource)->useModule('login_devices')->newQuery()->where('user_id', $user->id)->update(['status' => 'revoked']);
        }
        $this->audit($request, $user, 'status.changed', $previousStatus, $validated['status'], $validated['reason']);

        return response()->json($user->only(['id', 'name', 'email', 'phone', 'role', 'status', 'created_at']));
    }

    public function revokeSessions(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('security.manage'), 403);
        $revoked = $user->tokens()->count();
        $user->tokens()->delete();
        (new PlatformResource)->useModule('login_devices')->newQuery()->where('user_id', $user->id)->update(['status' => 'revoked']);
        $this->audit($request, $user, 'sessions.revoked', null, $user->status, 'Sessions revoked by a security administrator.');

        return response()->json(['revoked_sessions' => $revoked]);
    }

    public function auditHistory(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('security.manage'), 403);
        $logs = (new PlatformResource)->useModule('audit_logs')->newQuery()->latest()->get()
            ->filter(fn (PlatformResource $log): bool => data_get($log->data, 'record_type') === User::class && (int) data_get($log->data, 'record_id') === $user->id)
            ->values();

        return response()->json($logs);
    }

    private function audit(Request $request, User $user, string $event, ?string $previousStatus, string $status, string $reason): void
    {
        (new PlatformResource)->useModule('audit_logs')->fill([
            'company_id' => $user->company_id, 'user_id' => $request->user()->id, 'code' => "user.{$event}.".Str::uuid(), 'name' => "User {$event}", 'status' => 'recorded',
            'data' => ['record_type' => User::class, 'record_id' => $user->id, 'previous' => ['status' => $previousStatus], 'new' => ['status' => $status], 'reason' => $reason, 'ip_address' => $request->ip()],
        ])->save();
    }
}
