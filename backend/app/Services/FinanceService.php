<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class FinanceService
{
    public function allocateConfirmedBooking(Booking $booking, Payment $payment): Commission
    {
        return DB::transaction(function () use ($booking, $payment): Commission {
            $existing = Commission::where('booking_id', $booking->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $gross = (float) $booking->total;
            $platform = (float) $booking->platform_fee;
            $agentRate = in_array($booking->source, ['agent', 'offline'], true) ? (float) ($booking->trip->company->settings['agent_commission_rate'] ?? 0) : 0;
            $agent = round($gross * $agentRate / 100, 2);
            $operator = round($gross - $platform - $agent, 2);
            $commission = Commission::create(['company_id' => $booking->company_id, 'booking_id' => $booking->id, 'code' => 'COM-'.$booking->reference, 'name' => 'Booking revenue allocation', 'status' => 'available', 'amount' => $platform, 'currency' => $booking->currency, 'gross_amount' => $gross, 'platform_amount' => $platform, 'agent_amount' => $agent, 'operator_amount' => $operator, 'tax_amount' => (float) $booking->taxes, 'available_at' => now()]);
            $operatorWallet = $this->wallet($booking->company_id, 'operator', $booking->currency);
            $platformWallet = $this->wallet(null, 'platform', $booking->currency);
            $this->credit($operatorWallet, $operator, 'booking_revenue', 'booking:'.$booking->id.':operator', $booking, $payment, true);
            $this->credit($platformWallet, $platform, 'platform_commission', 'booking:'.$booking->id.':platform', $booking, $payment);

            return $commission;
        });
    }

    public function wallet(?int $companyId, string $type, string $currency): Wallet
    {
        return Wallet::firstOrCreate(['company_id' => $companyId, 'code' => $type.':'.strtoupper($currency)], ['name' => str($type)->headline().' wallet', 'wallet_type' => $type, 'status' => 'active', 'currency' => strtoupper($currency)]);
    }

    public function debitForSettlement(Wallet $wallet, float $amount, string $idempotencyKey): void
    {
        DB::transaction(function () use ($wallet, $amount, $idempotencyKey): void {
            $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            if (WalletTransaction::where('idempotency_key', $idempotencyKey)->exists()) {
                return;
            }
            abort_if((float) $wallet->held_balance < $amount || (float) $wallet->balance < $amount, 409, 'Wallet has insufficient settled funds.');
            $newBalance = round((float) $wallet->balance - $amount, 2);
            $wallet->update(['balance' => $newBalance, 'held_balance' => round((float) $wallet->held_balance - $amount, 2), 'last_transaction_at' => now()]);
            WalletTransaction::create(['company_id' => $wallet->company_id, 'wallet_id' => $wallet->id, 'code' => $idempotencyKey, 'name' => 'Settlement payout', 'status' => 'posted', 'amount' => $amount, 'currency' => $wallet->currency, 'transaction_type' => 'settlement', 'direction' => 'debit', 'balance_after' => $newBalance, 'idempotency_key' => $idempotencyKey, 'occurred_at' => now()]);
        });
    }

    public function payFromPassengerWallet(Booking $booking, Payment $payment, int $userId, ?string $pin = null): void
    {
        DB::transaction(function () use ($booking, $payment, $userId, $pin): void {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === 'paid') {
                return;
            }
            $wallet = Wallet::where('user_id', $userId)->where('wallet_type', 'passenger')->where('currency', $booking->currency)->lockForUpdate()->first();
            abort_unless($wallet && (float) $wallet->available_balance >= (float) $payment->amount, 422, 'Passenger wallet has insufficient funds.');
            abort_if($wallet->is_frozen, 422, 'Passenger wallet is frozen.');
            abort_if($wallet->security_pin && (! $pin || ! Hash::check($pin, $wallet->security_pin)), 422, 'The wallet PIN is incorrect.');
            $spentToday = (float) $wallet->transactions()->where('direction', 'debit')->whereDate('occurred_at', today())->sum('amount');
            abort_if($wallet->daily_spend_limit && $spentToday + (float) $payment->amount > (float) $wallet->daily_spend_limit, 422, 'The wallet daily spend limit would be exceeded.');
            $balance = round((float) $wallet->balance - (float) $payment->amount, 2);
            $available = round((float) $wallet->available_balance - (float) $payment->amount, 2);
            $wallet->update(['balance' => $balance, 'available_balance' => $available, 'last_transaction_at' => now()]);
            WalletTransaction::create(['company_id' => $wallet->company_id, 'user_id' => $userId, 'wallet_id' => $wallet->id, 'booking_id' => $booking->id, 'payment_id' => $payment->id, 'code' => 'booking:'.$booking->id.':passenger-wallet', 'name' => 'Passenger wallet booking payment', 'status' => 'posted', 'amount' => $payment->amount, 'currency' => $wallet->currency, 'transaction_type' => 'booking_payment', 'direction' => 'debit', 'balance_after' => $balance, 'idempotency_key' => 'payment:'.$payment->id, 'occurred_at' => now()]);
            $payment->update(['provider_reference' => 'WALLET-'.$payment->id, 'status' => 'paid', 'paid_at' => now(), 'provider_payload' => ['instructions' => 'Passenger wallet payment completed.']]);
        });
    }

    private function credit(Wallet $wallet, float $amount, string $type, string $idempotencyKey, Booking $booking, Payment $payment, bool $hold = false): void
    {
        if ($amount <= 0 || WalletTransaction::where('idempotency_key', $idempotencyKey)->exists()) {
            return;
        }
        $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
        $balance = round((float) $wallet->balance + $amount, 2);
        $wallet->update(['balance' => $balance, 'available_balance' => $hold ? $wallet->available_balance : round((float) $wallet->available_balance + $amount, 2), 'held_balance' => $hold ? round((float) $wallet->held_balance + $amount, 2) : $wallet->held_balance, 'last_transaction_at' => now()]);
        WalletTransaction::create(['company_id' => $wallet->company_id, 'wallet_id' => $wallet->id, 'booking_id' => $booking->id, 'payment_id' => $payment->id, 'code' => $idempotencyKey, 'name' => str($type)->headline(), 'status' => 'posted', 'amount' => $amount, 'currency' => $wallet->currency, 'transaction_type' => $type, 'direction' => 'credit', 'balance_after' => $balance, 'idempotency_key' => $idempotencyKey, 'occurred_at' => now()]);
    }
}
