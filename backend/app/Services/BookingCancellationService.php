<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPassenger;
use Illuminate\Support\Collection;

class BookingCancellationService
{
    /** @param Collection<int, BookingPassenger> $passengers */
    public function quote(Booking $booking, Collection $passengers): array
    {
        $booking->loadMissing('trip.company');
        $hours = now()->diffInHours($booking->trip->departs_at, false);
        $policy = collect(data_get($booking->trip->company?->settings, 'cancellation_policy', [
            ['minimum_hours' => 24, 'refund_percent' => 80], ['minimum_hours' => 6, 'refund_percent' => 50], ['minimum_hours' => 0, 'refund_percent' => 0],
        ]))->sortByDesc('minimum_hours')->values();
        $refundPercent = $booking->status === 'pending_payment' ? 0 : (float) ($policy->first(fn (array $rule): bool => $hours >= (int) $rule['minimum_hours'])['refund_percent'] ?? 0);
        $activeCount = max(1, $booking->passengers->whereIn('status', ['confirmed', 'held'])->count());
        $eligibleAmount = $booking->status === 'pending_payment' ? 0 : round((float) $booking->total * $passengers->count() / $activeCount, 2);
        $refundAmount = round($eligibleAmount * $refundPercent / 100, 2);

        return [
            'hours_before_departure' => $hours,
            'refund_percent' => $refundPercent,
            'eligible_amount' => $eligibleAmount,
            'cancellation_charge' => round($eligibleAmount - $refundAmount, 2),
            'refund_amount' => $refundAmount,
            'currency' => $booking->currency,
            'rules' => $policy->all(),
        ];
    }
}
