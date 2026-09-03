<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WalletService
{
    public function account(User $user): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $user->id, 'wallet_type' => 'passenger'],
            ['code' => 'WALLET-'.$user->id, 'name' => $user->name, 'status' => 'active', 'currency' => $user->currency ?? 'USD'],
        );
    }

    public function credit(User $user, float $amount, string $type, string $reference, string $description): WalletTransaction
    {
        return $this->transact($user, $amount, $type, $reference, $description, 'credit');
    }

    public function debit(User $user, float $amount, string $type, string $reference, string $description): WalletTransaction
    {
        return $this->transact($user, $amount, $type, $reference, $description, 'debit');
    }

    private function transact(User $user, float $amount, string $type, string $reference, string $description, string $direction): WalletTransaction
    {
        return DB::transaction(function () use ($user, $amount, $type, $reference, $description, $direction): WalletTransaction {
            $wallet = Wallet::query()->where('user_id', $user->id)->where('wallet_type', 'passenger')->lockForUpdate()->first()
                ?? $this->account($user);
            if ($wallet->is_frozen) {
                throw ValidationException::withMessages(['wallet' => 'This wallet is frozen.']);
            }
            $existing = WalletTransaction::where('idempotency_key', $reference)->first();
            if ($existing) {
                return $existing;
            }
            if ($direction === 'debit' && (float) $wallet->available_balance < $amount) {
                throw ValidationException::withMessages(['amount' => 'Insufficient wallet balance.']);
            }
            $signedAmount = $direction === 'credit' ? $amount : -$amount;
            $balance = round((float) $wallet->balance + $signedAmount, 2);
            $wallet->update(['balance' => $balance, 'available_balance' => $balance - (float) $wallet->held_balance, 'last_transaction_at' => now()]);

            return WalletTransaction::create([
                'wallet_id' => $wallet->id, 'user_id' => $user->id, 'code' => $reference, 'name' => $description,
                'status' => 'completed', 'currency' => $wallet->currency, 'amount' => $amount, 'transaction_type' => $type,
                'direction' => $direction, 'balance_after' => $balance, 'idempotency_key' => $reference, 'occurred_at' => now(),
            ]);
        });
    }
}
