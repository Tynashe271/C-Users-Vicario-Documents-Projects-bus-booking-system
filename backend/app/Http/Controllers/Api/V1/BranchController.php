<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Bus;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(Branch::where('company_id', $this->companyId($request))->withCount('users')->orderBy('name')->paginate(25));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $branch = Branch::create([...$request->validate($this->rules($companyId)), 'company_id' => $companyId]);

        return response()->json(['data' => $branch], 201);
    }

    public function show(Request $request, Branch $branch): JsonResponse
    {
        $this->authorizeBranch($request, $branch);

        return response()->json(['data' => $branch->loadCount('users')]);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $this->authorizeBranch($request, $branch);
        $branch->update($request->validate($this->rules($branch->company_id, $branch)));

        return response()->json(['data' => $branch->refresh()]);
    }

    public function destroy(Request $request, Branch $branch): JsonResponse
    {
        $this->authorizeBranch($request, $branch);
        abort_if($branch->users()->exists() || Bus::where('current_branch_id', $branch->id)->exists(), 409, 'Reassign branch staff and buses before removing this branch.');
        $branch->delete();

        return response()->json(status: 204);
    }

    public function report(Request $request, Branch $branch): JsonResponse
    {
        $this->authorizeBranch($request, $branch);
        $bookings = Booking::whereIn('user_id', User::where('branch_id', $branch->id)->select('id'));

        return response()->json(['branch' => $branch, 'staff_count' => $branch->users()->count(), 'bus_count' => Bus::where('current_branch_id', $branch->id)->count(), 'booking_count' => (clone $bookings)->count(), 'booking_revenue' => (float) $bookings->whereIn('status', ['confirmed', 'completed'])->sum('total')]);
    }

    private function companyId(Request $request): int
    {
        abort_unless($request->user()->company_id && $request->user()->can('companies.manage'), 403);

        return $request->user()->company_id;
    }

    private function authorizeBranch(Request $request, Branch $branch): void
    {
        abort_unless($branch->company_id === $this->companyId($request), 404);
    }

    private function rules(int $companyId, ?Branch $branch = null): array
    {
        $required = $branch ? 'sometimes' : 'required';

        return ['name' => [$required, 'string', 'max:150'], 'code' => [$required, 'string', 'max:50', Rule::unique('company_branches')->where('company_id', $companyId)->ignore($branch)], 'status' => ['sometimes', Rule::in(['active', 'inactive'])], 'contact_details' => ['nullable', 'array'], 'address' => [$required, 'array'], 'operating_hours' => [$required, 'array']];
    }
}
