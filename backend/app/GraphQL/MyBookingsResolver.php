<?php

namespace App\GraphQL;

use App\Models\Booking;
use GraphQL\Error\Error;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MyBookingsResolver
{
    /** @param array{status?:string} $arguments */
    public function __invoke(mixed $root, array $arguments): Collection
    {
        $user = auth('sanctum')->user();
        if (! $user) {
            throw new Error('Unauthenticated.');
        }

        return Booking::where('user_id', $user->id)
            ->when($arguments['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->with(['trip.company', 'trip.route.origin', 'trip.route.destination', 'passengers.seat', 'passengers.ticket', 'payments'])
            ->latest()->limit(50)->get();
    }
}
