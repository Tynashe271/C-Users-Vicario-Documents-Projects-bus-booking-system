<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\SeatLock;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(private readonly PricingService $pricing, private readonly PassengerJourneyNotificationService $notifications) {}

    public function lockSeats(Trip $trip, array $seatIds, ?int $userId): array
    {
        abort_unless(in_array($trip->status, ['published', 'available', 'almost_full'], true) && $trip->departs_at->isFuture(), 409, 'This trip is not open for booking.');
        $this->releaseExpiredBookings();
        $this->releaseExpiredSeatLocks();

        return Cache::lock("company:{$trip->company_id}:seat-locks:trip:{$trip->id}", 10)->block(5, function () use ($trip, $seatIds, $userId): array {
            return DB::transaction(function () use ($trip, $seatIds, $userId): array {
                $valid = $trip->bus()->firstOrFail()->seats()->whereIn('id', $seatIds)->count();
                if ($valid !== count(array_unique($seatIds))) {
                    throw ValidationException::withMessages(['seats' => 'One or more seats do not belong to this trip bus.']);
                }
                $booked = BookingPassenger::where('trip_id', $trip->id)->whereIn('seat_id', $seatIds)
                    ->where(function ($query): void {
                        $query->where('status', 'confirmed')->orWhere(function ($held): void {
                            $held->where('status', 'held')->whereHas('booking', fn ($booking) => $booking->where('payable_until', '>', now()));
                        });
                    })->exists();
                if ($booked) {
                    throw ValidationException::withMessages(['seats' => 'One or more seats are already booked.']);
                }
                $token = (string) Str::uuid();
                $expires = now()->addMinutes((int) config('booking.seat_lock_minutes', 10));
                foreach (array_unique($seatIds) as $seatId) {
                    $lock = SeatLock::where(['trip_id' => $trip->id, 'seat_id' => $seatId])->lockForUpdate()->first();
                    if ($lock && $lock->expires_at->isFuture()) {
                        throw ValidationException::withMessages(['seats' => 'One or more seats are temporarily held.']);
                    }
                    $lock?->delete();
                    SeatLock::create(['token' => $token, 'trip_id' => $trip->id, 'seat_id' => $seatId, 'user_id' => $userId, 'expires_at' => $expires]);
                }

                return ['token' => $token, 'expires_at' => $expires->toIso8601String()];
            });
        });
    }

    public function create(Trip $trip, string $lockToken, array $data, ?int $userId): Booking
    {
        try {
            return DB::transaction(function () use ($trip, $lockToken, $data, $userId) {
                $locks = SeatLock::where('token', $lockToken)->where('trip_id', $trip->id)->where('expires_at', '>', now())->lockForUpdate()->get();
                if ($locks->isEmpty() || $locks->count() !== count($data['passengers'])) {
                    throw ValidationException::withMessages(['lock_token' => 'Seat hold is invalid, expired, or does not match passengers.']);
                }
                $seatIds = $locks->pluck('seat_id')->sort()->values()->all();
                $submitted = collect($data['passengers'])->pluck('seat_id')->sort()->values()->all();
                if ($seatIds !== $submitted) {
                    throw ValidationException::withMessages(['passengers' => 'Passenger seats must match the held seats.']);
                }
                $quote = $this->pricing->quote($trip, $data['passengers'], $data['optional_services'] ?? [], $data['coupon_code'] ?? null, true, $userId);
                $fareBreakdown = [...$quote, 'terms' => ['version' => config('booking.terms_version'), 'accepted_at' => now()->toIso8601String()]];
                $booking = Booking::create(['public_id' => Str::uuid(), 'reference' => 'BK'.strtoupper(Str::random(8)), 'company_id' => $trip->company_id, 'trip_id' => $trip->id, 'user_id' => $userId,
                    'contact_name' => $data['contact_name'], 'contact_email' => $data['contact_email'], 'contact_phone' => $data['contact_phone'], 'subtotal' => $quote['subtotal'], 'discount' => $quote['discount'], 'taxes' => $quote['taxes'], 'fees' => $quote['fees'], 'platform_fee' => $quote['platform_fee'], 'total' => $quote['total'], 'currency' => $trip->currency, 'booking_type' => $data['booking_type'] ?? 'single', 'source' => $data['source'] ?? 'web', 'journey_group' => $data['journey_group'] ?? null, 'fare_breakdown' => $fareBreakdown, 'payable_until' => $locks->min('expires_at')]);
                foreach ($data['passengers'] as $index => $passenger) {
                    $details = array_merge($passenger['details'] ?? [], [
                        'phone' => $passenger['phone'], 'email' => $passenger['email'], 'passport_number' => $passenger['passport_number'] ?? null,
                        'emergency_contact' => $passenger['emergency_contact'] ?? null, 'accessibility_requirements' => $passenger['accessibility_requirements'] ?? null,
                    ]);
                    $booking->passengers()->create(['trip_id' => $trip->id, 'seat_id' => $passenger['seat_id'], 'full_name' => $passenger['full_name'], 'type' => $passenger['type'], 'document_number' => $passenger['document_number'] ?? null, 'details' => $details, 'fare' => $quote['passenger_fares'][$index], 'status' => 'held']);
                }
                $locks->each->delete();

                return $booking->load('passengers.seat');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['seats' => 'One or more seats were booked by another passenger.']);
        }
    }

    public function releaseExpiredBookings(): int
    {
        return DB::transaction(function (): int {
            $bookings = Booking::where('status', 'pending_payment')->where('payable_until', '<=', now())->lockForUpdate()->get();

            foreach ($bookings as $booking) {
                $this->pricing->restoreCoupon($booking);
                $booking->passengers()->delete();
                $booking->update(['status' => 'expired']);
                $this->notifications->seatLockExpired($booking);
            }

            return $bookings->count();
        });
    }

    public function releaseExpiredSeatLocks(): int
    {
        $locks = SeatLock::where('expires_at', '<=', now())->get();
        foreach ($locks->groupBy('token') as $token => $group) {
            $user = $group->first()->user_id ? User::find($group->first()->user_id) : null;
            if ($user) {
                $this->notifications->seatHoldExpired($user, (string) $token, (int) $group->first()->trip_id);
            }
        }
        SeatLock::whereKey($locks->pluck('id'))->delete();

        return $locks->count();
    }

    public function releaseAfterPermanentPaymentFailure(Booking $booking): void
    {
        DB::transaction(function () use ($booking): void {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($booking->status !== 'pending_payment') {
                return;
            }

            $this->pricing->restoreCoupon($booking);
            $booking->passengers()->delete();
            $booking->update(['status' => 'payment_failed', 'payable_until' => null]);
        });
    }

    public function issueTickets(Booking $booking, TicketDeliveryService $delivery): void
    {
        foreach ($booking->passengers as $passenger) {
            $ticket = Ticket::firstOrCreate(['booking_passenger_id' => $passenger->id], ['public_id' => Str::uuid(), 'ticket_number' => 'TK'.strtoupper(Str::random(10)), 'qr_token' => hash('sha256', Str::uuid())]);
            $ticket->update(['status' => 'active']);
        }

        $delivery->queue($booking->refresh()->load('passengers.ticket', 'trip'));
    }
}
