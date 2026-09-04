<?php

namespace App\GraphQL;

use App\Models\Booking;
use App\Models\NotificationRecord;
use GraphQL\Error\Error;

class PassengerDashboardResolver
{
    /** @return array<string, mixed> */
    public function __invoke(mixed $root, array $arguments): array
    {
        $user = auth('sanctum')->user();
        if (! $user) {
            throw new Error('Unauthenticated.');
        }

        $upcomingBookings = Booking::where('user_id', $user->id)->whereIn('status', ['confirmed', 'pending_payment'])
            ->with(['trip.company', 'trip.route.origin', 'trip.route.destination', 'passengers.seat', 'passengers.ticket', 'payments'])
            ->get()->filter(fn (Booking $booking): bool => $booking->trip?->departs_at?->isFuture() ?? false)
            ->sortBy(fn (Booking $booking) => $booking->trip->departs_at)->take(5)->values();

        return [
            'upcoming_bookings' => $upcomingBookings,
            'unread_notifications' => NotificationRecord::where('user_id', $user->id)->where('channel', 'in_app')->whereNull('read_at')->count(),
            'loyalty_points_balance' => $user->loyaltyAccount?->points_balance ?? 0,
            'loyalty_membership_level' => $user->loyaltyAccount?->membership_level,
            'wallet_balance' => (float) ($user->wallet?->balance ?? 0),
        ];
    }
}
