<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\PlatformResource;
use App\Models\Reconciliation;
use App\Models\Settlement;
use App\Models\Wallet;
use App\Services\FinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $wallets = Wallet::where('company_id', $companyId)->get();
        $commissions = Commission::where('company_id', $companyId);

        return response()->json(['wallets' => $wallets, 'gross_booking_value' => (float) (clone $commissions)->sum('gross_amount'), 'platform_fees' => (float) (clone $commissions)->sum('platform_amount'), 'operator_revenue' => (float) (clone $commissions)->sum('operator_amount'), 'unsettled_amount' => (float) (clone $commissions)->where('status', 'available')->sum('operator_amount'), 'pending_settlements' => Settlement::where('company_id', $companyId)->whereIn('status', ['draft', 'approved'])->count()]);
    }

    public function createSettlement(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $validated = $request->validate(['period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'], 'currency' => ['required', 'string', 'size:3']]);
        $settlement = DB::transaction(function () use ($companyId, $validated, $request): Settlement {
            $commissions = Commission::where('company_id', $companyId)->where('currency', strtoupper($validated['currency']))->where('status', 'available')->whereBetween('available_at', [$validated['period_start'].' 00:00:00', $validated['period_end'].' 23:59:59'])->lockForUpdate()->get();
            abort_if($commissions->isEmpty(), 422, 'No unsettled booking revenue exists for this period.');
            $settlement = Settlement::create(['public_id' => Str::uuid(), 'company_id' => $companyId, 'user_id' => $request->user()->id, 'code' => 'SET-'.strtoupper(Str::random(10)), 'name' => 'Operator settlement', 'status' => 'draft', 'currency' => strtoupper($validated['currency']), 'period_start' => $validated['period_start'], 'period_end' => $validated['period_end'], 'gross_amount' => $commissions->sum('gross_amount'), 'platform_fees' => $commissions->sum('platform_amount'), 'agent_fees' => $commissions->sum('agent_amount'), 'net_amount' => $commissions->sum('operator_amount')]);
            foreach ($commissions as $commission) {
                $label = $commission->booking_id ? 'Booking '.$commission->booking->reference : 'Parcel '.$commission->parcel->tracking_number;
                $settlement->items()->create(['company_id' => $companyId, 'booking_id' => $commission->booking_id, 'parcel_id' => $commission->parcel_id, 'commission_id' => $commission->id, 'code' => $settlement->code.':'.$commission->id, 'name' => $label, 'status' => 'pending', 'currency' => $commission->currency, 'gross_amount' => $commission->gross_amount, 'fee_amount' => (float) $commission->platform_amount + (float) $commission->agent_amount, 'net_amount' => $commission->operator_amount]);
            }
            $commissions->each->update(['status' => 'in_settlement']);

            return $settlement->load('items');
        });

        return response()->json($settlement, 201);
    }

    public function approve(Request $request, Settlement $settlement): JsonResponse
    {
        $this->authorizeSettlement($request, $settlement);
        abort_unless($settlement->status === 'draft', 409, 'Only draft settlements can be approved.');
        abort_if($settlement->user_id === $request->user()->id, 409, 'A second finance user must approve this settlement.');
        $settlement->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
        $this->auditSettlement($request, $settlement, 'approved', ['net_amount' => (float) $settlement->net_amount]);

        return response()->json($settlement->refresh());
    }

    public function pay(Request $request, Settlement $settlement, FinanceService $finance): JsonResponse
    {
        $this->authorizeSettlement($request, $settlement);
        $validated = $request->validate(['payment_reference' => ['required', 'string', 'max:191']]);
        abort_unless($settlement->status === 'approved', 409, 'Settlement must be approved before payment.');
        abort_if($settlement->approved_by === $request->user()->id, 409, 'A different finance user must release payment.');
        DB::transaction(function () use ($settlement, $validated, $request, $finance): void {
            $wallet = Wallet::where('company_id', $settlement->company_id)->where('wallet_type', 'operator')->where('currency', $settlement->currency)->firstOrFail();
            $finance->debitForSettlement($wallet, (float) $settlement->net_amount, 'settlement:'.$settlement->id);
            Commission::whereIn('id', $settlement->items()->pluck('commission_id'))->update(['status' => 'settled', 'settled_at' => now()]);
            $settlement->items()->update(['status' => 'paid']);
            $settlement->update(['status' => 'paid', 'paid_by' => $request->user()->id, 'paid_at' => now(), 'payment_reference' => $validated['payment_reference']]);
        });
        $this->auditSettlement($request, $settlement, 'paid', ['net_amount' => (float) $settlement->net_amount, 'payment_reference' => $validated['payment_reference']]);

        return response()->json($settlement->refresh()->load('items'));
    }

    public function reconcile(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $validated = $request->validate(['provider' => ['required', 'string', 'max:50'], 'date' => ['required', 'date', 'before_or_equal:today'], 'reported_amount' => ['required', 'numeric', 'min:0'], 'reported_transactions' => ['required', 'integer', 'min:0']]);
        $payments = Payment::query()->whereHas('booking', fn ($query) => $query->where('company_id', $companyId))->where('provider', $validated['provider'])->where('status', 'paid')->whereDate('paid_at', $validated['date']);
        $expectedAmount = (float) $payments->sum('amount');
        $expectedTransactions = $payments->count();
        $difference = round((float) $validated['reported_amount'] - $expectedAmount, 2);
        $record = Reconciliation::updateOrCreate(['company_id' => $companyId, 'provider' => $validated['provider'], 'reconciliation_date' => $validated['date']], ['user_id' => $request->user()->id, 'code' => $validated['provider'].':'.$validated['date'], 'name' => str($validated['provider'])->headline().' reconciliation', 'status' => $difference === 0.0 && $expectedTransactions === $validated['reported_transactions'] ? 'matched' : 'exception', 'currency' => 'USD', 'expected_amount' => $expectedAmount, 'reported_amount' => $validated['reported_amount'], 'difference_amount' => $difference, 'expected_transactions' => $expectedTransactions, 'reported_transactions' => $validated['reported_transactions']]);

        return response()->json($record, 201);
    }

    private function companyId(Request $request): int
    {
        abort_unless($request->user()->company_id && $request->user()->can('finance.manage'), 403);

        return $request->user()->company_id;
    }

    private function authorizeSettlement(Request $request, Settlement $settlement): void
    {
        abort_unless($settlement->company_id === $this->companyId($request), 404);
    }

    /** @param array<string, mixed> $details */
    private function auditSettlement(Request $request, Settlement $settlement, string $event, array $details): void
    {
        (new PlatformResource)->useModule('audit_logs')->fill([
            'company_id' => $settlement->company_id, 'user_id' => $request->user()->id, 'code' => 'settlement.'.$event.'.'.Str::uuid(), 'name' => "Settlement {$event}", 'status' => 'recorded',
            'data' => ['record_type' => Settlement::class, 'record_id' => $settlement->id, 'event' => "settlement.{$event}", ...$details, 'ip_address' => $request->ip()],
        ])->save();
    }
}
