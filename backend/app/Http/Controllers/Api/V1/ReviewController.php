<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request, Booking $booking): JsonResponse
    {
        abort_unless($booking->user_id === $request->user()->id, 404);
        abort_unless(in_array($booking->status, ['confirmed', 'completed'], true) && $booking->trip->arrives_at->isPast(), 409, 'A review can be submitted after the trip is completed.');
        $validated = $request->validate([
            'cleanliness' => ['required', 'integer', 'between:1,5'], 'comfort' => ['required', 'integer', 'between:1,5'],
            'punctuality' => ['required', 'integer', 'between:1,5'], 'driver_professionalism' => ['required', 'integer', 'between:1,5'],
            'customer_service' => ['required', 'integer', 'between:1,5'], 'overall_experience' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $score = round(collect($validated)->except('comment')->average(), 2);
        $review = Review::updateOrCreate(['booking_id' => $booking->id], [...$validated, 'company_id' => $booking->company_id, 'user_id' => $request->user()->id, 'trip_id' => $booking->trip_id, 'code' => 'review-'.$booking->id, 'name' => 'Trip review', 'status' => 'active', 'amount' => $score]);

        return response()->json($review, $review->wasRecentlyCreated ? 201 : 200);
    }
}
