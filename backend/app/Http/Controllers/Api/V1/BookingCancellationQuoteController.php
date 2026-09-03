<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingCancellationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingCancellationQuoteController extends Controller
{
    public function __invoke(Request $request, Booking $booking, BookingCancellationService $cancellations): JsonResponse
    {
        abort_unless($booking->user_id === $request->user()->id || ($booking->company_id === $request->user()->company_id && $request->user()->can('bookings.manage')), 404);
        abort_unless(in_array($booking->status, ['confirmed', 'pending_payment'], true), 409, 'This booking cannot be cancelled.');
        $validated = $request->validate(['passenger_ids' => ['nullable', 'array', 'min:1'], 'passenger_ids.*' => ['integer', 'distinct']]);
        $booking->load('passengers', 'trip.company');
        $passengers = isset($validated['passenger_ids']) ? $booking->passengers->whereIn('id', $validated['passenger_ids']) : $booking->passengers->whereIn('status', ['confirmed', 'held']);
        abort_if($passengers->isEmpty() || $passengers->count() !== count($validated['passenger_ids'] ?? $passengers), 422, 'One or more passengers cannot be cancelled.');

        return response()->json($cancellations->quote($booking, $passengers));
    }
}
