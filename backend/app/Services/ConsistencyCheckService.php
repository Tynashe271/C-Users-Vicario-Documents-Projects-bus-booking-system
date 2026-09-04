<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Commission;
use App\Models\PlatformResource;
use App\Models\SeatLock;
use App\Models\Settlement;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Defence-in-depth data-integrity sweep for the reliability-critical parts of the booking/finance
 * flow. Every condition checked here is something the application already tries hard to prevent
 * up front (atomic seat locks, unique constraints, idempotency keys, scheduled expiry jobs) — this
 * exists to catch the rare case where prevention failed anyway (a crashed job, a race the lock
 * didn't cover, a manual DB edit) before it becomes a support ticket. Read-only: it reports issues,
 * it never "fixes" data on its own.
 */
class ConsistencyCheckService
{
    /** @return Collection<int, array{check: string, severity: string, company_id: ?int, message: string, context: array<string, mixed>}> */
    public function run(): Collection
    {
        return collect([
            ...$this->walletBalanceDrift(),
            ...$this->confirmedBookingsUnderpaid(),
            ...$this->staleUnreleasedBookings(),
            ...$this->settlementCommissionMismatches(),
            ...$this->expiredSeatLocksStillHeld(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function walletBalanceDrift(): array
    {
        $issues = [];
        Wallet::query()->chunkById(100, function (Collection $wallets) use (&$issues): void {
            foreach ($wallets as $wallet) {
                $credits = (float) WalletTransaction::where('wallet_id', $wallet->id)->where('direction', 'credit')->sum('amount');
                $debits = (float) WalletTransaction::where('wallet_id', $wallet->id)->where('direction', 'debit')->sum('amount');
                $expected = round($credits - $debits, 2);
                $actual = round((float) $wallet->balance, 2);
                if (abs($expected - $actual) > 0.01) {
                    $issues[] = ['check' => 'wallet_balance_drift', 'severity' => 'critical', 'company_id' => $wallet->company_id, 'message' => "Wallet #{$wallet->id} balance ({$actual}) does not match its transaction history ({$expected}).", 'context' => ['wallet_id' => $wallet->id, 'recorded_balance' => $actual, 'expected_balance' => $expected]];
                }
            }
        });

        return $issues;
    }

    /** @return list<array<string, mixed>> */
    private function confirmedBookingsUnderpaid(): array
    {
        $issues = [];
        Booking::query()->where('status', 'confirmed')->with('payments')->chunkById(100, function (Collection $bookings) use (&$issues): void {
            foreach ($bookings as $booking) {
                $paid = (float) $booking->payments->where('status', 'paid')->sum('amount');
                if (round($paid, 2) < round((float) $booking->total, 2) - 0.01) {
                    $issues[] = ['check' => 'confirmed_booking_underpaid', 'severity' => 'critical', 'company_id' => $booking->company_id, 'message' => "Booking {$booking->reference} is confirmed but only {$paid} of {$booking->total} {$booking->currency} has been paid.", 'context' => ['booking_id' => $booking->id, 'paid' => $paid, 'total' => (float) $booking->total]];
                }
            }
        });

        return $issues;
    }

    /** @return list<array<string, mixed>> */
    private function staleUnreleasedBookings(): array
    {
        return Booking::query()->where('status', 'pending_payment')->whereNotNull('payable_until')
            ->where('payable_until', '<', now()->subMinutes(5))
            ->get(['id', 'reference', 'company_id', 'payable_until'])
            ->map(fn (Booking $booking) => ['check' => 'stale_unreleased_booking', 'severity' => 'warning', 'company_id' => $booking->company_id, 'message' => "Booking {$booking->reference} expired at {$booking->payable_until} but was never released.", 'context' => ['booking_id' => $booking->id, 'payable_until' => $booking->payable_until?->toIso8601String()]])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function settlementCommissionMismatches(): array
    {
        $issues = [];
        Settlement::query()->where('status', 'paid')->with('items')->chunkById(100, function (Collection $settlements) use (&$issues): void {
            foreach ($settlements as $settlement) {
                $commissionIds = $settlement->items->pluck('commission_id')->filter();
                $unsettled = Commission::whereIn('id', $commissionIds)->where('status', '!=', 'settled')->pluck('id');
                if ($unsettled->isNotEmpty()) {
                    $issues[] = ['check' => 'settlement_commission_mismatch', 'severity' => 'critical', 'company_id' => $settlement->company_id, 'message' => "Settlement {$settlement->code} is paid but ".$unsettled->count().' linked commission(s) are not marked settled.', 'context' => ['settlement_id' => $settlement->id, 'commission_ids' => $unsettled->values()->all()]];
                }
            }
        });

        return $issues;
    }

    /** @return list<array<string, mixed>> */
    private function expiredSeatLocksStillHeld(): array
    {
        return SeatLock::query()->where('expires_at', '<', now()->subMinutes(5))
            ->get(['id', 'trip_id', 'seat_id', 'expires_at'])
            ->map(fn (SeatLock $lock) => ['check' => 'expired_seat_lock_still_held', 'severity' => 'warning', 'company_id' => null, 'message' => "Seat lock #{$lock->id} (trip {$lock->trip_id}, seat {$lock->seat_id}) expired at {$lock->expires_at} but was never released.", 'context' => ['seat_lock_id' => $lock->id, 'trip_id' => $lock->trip_id, 'seat_id' => $lock->seat_id]])
            ->all();
    }

    /** Persists each detected issue to the consistency_checks module for the admin report endpoint. */
    public function runAndLog(): Collection
    {
        $issues = $this->run();
        $runId = now()->format('YmdHisu');
        foreach ($issues as $issue) {
            (new PlatformResource)->useModule('consistency_checks')->fill([
                'company_id' => $issue['company_id'], 'code' => "{$issue['check']}:{$runId}:".Str::random(6), 'name' => $issue['message'], 'status' => $issue['severity'],
                'data' => ['check' => $issue['check'], 'severity' => $issue['severity'], 'context' => $issue['context']],
            ])->save();
        }

        return $issues;
    }
}
