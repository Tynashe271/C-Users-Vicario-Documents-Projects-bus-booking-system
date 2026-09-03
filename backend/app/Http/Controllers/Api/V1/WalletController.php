<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class WalletController extends Controller
{
    public function show(Request $request, WalletService $walletService): JsonResponse
    {
        $wallet = $walletService->account($request->user());

        return response()->json(['wallet' => $wallet, 'transactions' => $wallet->transactions()->latest('occurred_at')->paginate(50)]);
    }

    public function deposit(Request $request, WalletService $walletService): JsonResponse
    {
        abort_if(app()->isProduction(), 501, 'Wallet deposits must be confirmed by a configured payment provider.');
        $validated = $request->validate(['amount' => ['required', 'numeric', 'between:1,10000'], 'reference' => ['required', 'string', 'max:100']]);
        $transaction = $walletService->credit($request->user(), (float) $validated['amount'], 'deposit', 'deposit:'.$request->user()->id.':'.$validated['reference'], 'Wallet deposit');

        return response()->json(['transaction' => $transaction, 'wallet' => $transaction->wallet()->first()], 201);
    }

    public function statement(Request $request, WalletService $walletService): JsonResponse
    {
        $validated = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $wallet = $walletService->account($request->user());
        $query = $wallet->transactions()->orderByDesc('occurred_at');
        if (isset($validated['from'])) {
            $query->whereDate('occurred_at', '>=', $validated['from']);
        }
        if (isset($validated['to'])) {
            $query->whereDate('occurred_at', '<=', $validated['to']);
        }
        $transactions = $query->get();

        return response()->json(['wallet' => $wallet, 'period' => $validated, 'credits' => (float) $transactions->where('direction', 'credit')->sum('amount'), 'debits' => (float) $transactions->where('direction', 'debit')->sum('amount'), 'transactions' => $transactions]);
    }

    public function updateSecurity(Request $request, WalletService $walletService): JsonResponse
    {
        $validated = $request->validate(['pin' => ['nullable', 'digits:4'], 'is_frozen' => ['sometimes', 'boolean'], 'daily_spend_limit' => ['nullable', 'numeric', 'between:1,100000']]);
        $wallet = $walletService->account($request->user());
        if (array_key_exists('pin', $validated)) {
            $validated['security_pin'] = $validated['pin'] === null ? null : Hash::make($validated['pin']);
            unset($validated['pin']);
        }
        $wallet->update($validated);

        return response()->json(['wallet' => $wallet->refresh()]);
    }
}
