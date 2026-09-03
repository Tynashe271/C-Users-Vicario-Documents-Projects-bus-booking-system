<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RoleAssignmentAudit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class RoleManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('agents.manage'), 403);

        return response()->json(['data' => Role::query()->with('permissions:id,name')->orderBy('name')->get()]);
    }

    public function assign(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('agents.manage'), 403);
        abort_unless($request->user()->company_id && $user->company_id === $request->user()->company_id, 404);
        $validated = $request->validate(['role' => ['required', 'string', 'in:'.implode(',', config('platform.company_roles'))]]);
        $previousRoles = $user->getRoleNames()->values()->all();
        $user->syncRoles([$validated['role']]);
        $user->update(['role' => $validated['role']]);
        RoleAssignmentAudit::create([
            'company_id' => $user->company_id,
            'branch_id' => $user->branch_id,
            'actor_id' => $request->user()->id,
            'user_id' => $user->id,
            'role' => $validated['role'],
            'action' => 'assigned',
            'previous_roles' => $previousRoles,
            'new_roles' => [$validated['role']],
        ]);

        return response()->json(['data' => $user->load('roles.permissions')]);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('agents.manage') && $request->user()->company_id && $user->company_id === $request->user()->company_id, 404);
        abort_if($request->user()->is($user), 422, 'You cannot change your own account status.');
        $validated = $request->validate(['status' => ['required', Rule::in(['active', 'suspended', 'removed'])]]);
        $user->forceFill(['status' => $validated['status'], 'deactivated_at' => $validated['status'] === 'active' ? null : now()])->save();

        return response()->json(['data' => $user]);
    }
}
