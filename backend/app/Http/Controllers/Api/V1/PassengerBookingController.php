<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\ReceiptDocumentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class PassengerBookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['nullable', Rule::in(['upcoming', 'completed', 'cancelled', 'pending'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $bookings = Booking::query()
            ->where('user_id', $request->user()->id)
            ->with(['trip.company:id,name', 'trip.route.origin', 'trip.route.destination', 'passengers.seat', 'passengers.ticket', 'review', 'payments' => fn ($query) => $query->latest('id')])
            ->when($validated['category'] ?? null, function (Builder $query, string $category): void {
                match ($category) {
                    'upcoming' => $query->where('status', 'confirmed')->whereHas('trip', fn (Builder $trip): Builder => $trip->where('arrives_at', '>=', now())),
                    'completed' => $query->whereIn('status', ['confirmed', 'completed'])->whereHas('trip', fn (Builder $trip): Builder => $trip->where('arrives_at', '<', now())),
                    'cancelled' => $query->whereIn('status', ['cancelled', 'partially_cancelled']),
                    'pending' => $query->whereIn('status', ['pending_payment', 'payment_failed', 'expired']),
                };
            })
            ->latest('id')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json($bookings);
    }

    public function receipt(Request $request, Booking $booking, ReceiptDocumentService $documents): Response
    {
        abort_unless($booking->user_id === $request->user()->id, 404);
        abort_unless($booking->payments()->where('status', 'paid')->exists(), 409, 'A receipt is available after payment.');

        return response($documents->pdf($booking))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="receipt-'.$booking->reference.'.pdf"');
    }
}
