<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PlatformResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:150'],
            'branch_id' => ['nullable', Rule::exists('company_branches', 'id')->where('company_id', $companyId)],
            'transaction_limit' => ['nullable', 'numeric', 'min:0'],
        ]);
        abort_if((new PlatformResource)->useModule('agents')->newQuery()->where('company_id', $companyId)->where('user_id', $validated['user_id'])->exists(), 409, 'This user is already registered as an agent.');
        $agent = (new PlatformResource)->useModule('agents');
        $agent->fill(['company_id' => $companyId, 'user_id' => $validated['user_id'], 'code' => 'AGT-'.$validated['user_id'], 'name' => $validated['name'], 'status' => 'pending', 'amount' => $validated['transaction_limit'] ?? null, 'currency' => 'USD', 'data' => ['branch_id' => $validated['branch_id'] ?? null]]);
        $agent->save();

        return response()->json($agent, 201);
    }

    public function verify(Request $request, int $agent): JsonResponse
    {
        $record = $this->find($request, $agent);
        abort_unless($record->status === 'pending', 409, 'Only a pending agent application can be verified.');
        $validated = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => ['required_if:decision,rejected', 'nullable', 'string', 'max:1000']]);
        $record->fill(['status' => $validated['decision'], 'data' => [...($record->data ?? []), 'decision_reason' => $validated['reason'] ?? null]])->save();

        return response()->json($record->refresh());
    }

    public function suspend(Request $request, int $agent): JsonResponse
    {
        $record = $this->find($request, $agent);
        abort_unless($record->status === 'approved', 409, 'Only an approved agent can be suspended.');
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $record->fill(['status' => 'suspended', 'data' => [...($record->data ?? []), 'suspension_reason' => $validated['reason']]])->save();

        return response()->json($record->refresh());
    }

    public function activate(Request $request, int $agent): JsonResponse
    {
        $record = $this->find($request, $agent);
        abort_unless($record->status === 'suspended', 409, 'Only a suspended agent can be reactivated.');
        $record->fill(['status' => 'approved'])->save();

        return response()->json($record->refresh());
    }

    public function updateLimit(Request $request, int $agent): JsonResponse
    {
        $record = $this->find($request, $agent);
        $validated = $request->validate(['transaction_limit' => ['required', 'numeric', 'min:0']]);
        $record->fill(['amount' => $validated['transaction_limit']])->save();

        return response()->json($record->refresh());
    }

    public function dailyReport(Request $request, int $agent): JsonResponse
    {
        $record = $this->find($request, $agent);
        $bookings = Booking::where('company_id', $record->company_id)->where('user_id', $record->user_id)->where('source', 'agent')->whereDate('created_at', today())->get();

        return response()->json([
            'agent_id' => $record->id, 'date' => today()->toDateString(),
            'bookings' => $bookings->count(), 'gross_total' => round((float) $bookings->whereIn('status', ['confirmed', 'completed', 'pending_payment'])->sum('total'), 2),
            'cancelled' => $bookings->whereIn('status', ['cancelled', 'partially_cancelled'])->count(),
        ]);
    }

    private function companyId(Request $request): int
    {
        abort_unless($request->user()->can('agents.manage') && $request->user()->company_id, 403);

        return $request->user()->company_id;
    }

    private function find(Request $request, int $agent): PlatformResource
    {
        $record = (new PlatformResource)->useModule('agents')->newQuery()->where('company_id', $this->companyId($request))->findOrFail($agent);

        return $record;
    }
}
