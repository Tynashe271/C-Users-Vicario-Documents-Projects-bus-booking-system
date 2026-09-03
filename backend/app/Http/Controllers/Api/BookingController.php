<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Trip;
use App\Services\BookingService;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(private BookingService $service) {}

    public function lock(Request $r, Trip $trip): JsonResponse
    {
        $v = $r->validate(['seat_ids' => 'required|array|min:1|max:10', 'seat_ids.*' => 'integer|distinct']);

        return response()->json($this->service->lockSeats($trip, $v['seat_ids'], $r->user('sanctum')?->id), 201);
    }

    public function store(Request $r, Trip $trip): JsonResponse
    {
        $v = $r->validate([
            'lock_token' => ['required', 'uuid'], 'contact_name' => ['required', 'string', 'max:150'], 'contact_email' => ['required', 'email', 'max:254'], 'contact_phone' => ['required', 'string', 'max:30'],
            'booking_type' => ['sometimes', 'in:single,group,family,corporate,agent,return,connecting,bulk,reserved,pay_later,offline'], 'source' => ['sometimes', 'in:web,mobile,agent,branch,offline,corporate'], 'journey_group' => ['nullable', 'uuid'],
            'coupon_code' => ['nullable', 'string', 'max:100'], 'optional_services' => ['sometimes', 'array', 'max:10'], 'optional_services.*' => ['string', 'max:100', 'distinct'], 'booking_terms_accepted' => ['required', 'accepted'],
            'passengers' => ['required', 'array', 'min:1', 'max:10'], 'passengers.*.seat_id' => ['required', 'integer', 'distinct'], 'passengers.*.full_name' => ['required', 'string', 'max:150'],
            'passengers.*.phone' => ['required', 'string', 'max:30'], 'passengers.*.email' => ['required', 'email', 'max:254'], 'passengers.*.type' => ['required', 'in:adult,child,infant,student,senior'],
            'passengers.*.document_number' => ['nullable', 'string', 'max:100'], 'passengers.*.passport_number' => ['nullable', 'string', 'max:100'],
            'passengers.*.emergency_contact' => ['nullable', 'array'], 'passengers.*.emergency_contact.name' => ['required_with:passengers.*.emergency_contact.phone', 'string', 'max:150'], 'passengers.*.emergency_contact.phone' => ['required_with:passengers.*.emergency_contact.name', 'string', 'max:30'],
            'passengers.*.accessibility_requirements' => ['nullable', 'string', 'max:1000'], 'passengers.*.details' => ['nullable', 'array'],
        ]);

        return response()->json($this->service->create($trip, $v['lock_token'], $v, $r->user('sanctum')?->id), 201);
    }

    public function quote(Request $request, Trip $trip, PricingService $pricing): JsonResponse
    {
        $validated = $request->validate(['passengers' => ['required', 'array', 'min:1', 'max:50'], 'passengers.*.type' => ['required', 'in:adult,child,infant,student,senior'], 'passengers.*.seat_id' => ['nullable', 'integer', 'distinct'], 'optional_services' => ['sometimes', 'array', 'max:10'], 'optional_services.*' => ['string', 'max:100', 'distinct'], 'coupon_code' => ['nullable', 'string', 'max:100']]);

        return response()->json($pricing->quote($trip, $validated['passengers'], $validated['optional_services'] ?? [], $validated['coupon_code'] ?? null, false, $request->user('sanctum')?->id));
    }

    public function show(Request $r, Booking $booking)
    {
        abort_unless($r->user() && ($r->user()->id === $booking->user_id || $r->user()->company_id === $booking->company_id), 403);

        return $booking->load(['trip.company:id,name', 'trip.route.origin', 'trip.route.destination', 'passengers.seat', 'passengers.ticket', 'payments']);
    }
}
