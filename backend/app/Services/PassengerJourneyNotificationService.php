<?php

namespace App\Services;

use App\Jobs\DeliverPlatformNotification;
use App\Models\Booking;
use App\Models\NotificationRecord;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Str;

class PassengerJourneyNotificationService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function bookingCancelled(Booking $booking, array $quote): void
    {
        $this->sendBooking($booking, 'trip_cancellation', 'Booking cancelled', "Booking {$booking->reference} was cancelled. Refund due: {$booking->currency} ".number_format((float) $quote['refund_amount'], 2), $quote, 'cancellation:'.$booking->id.':'.$booking->updated_at?->timestamp);
        if ((float) $quote['refund_amount'] > 0) {
            $this->sendBooking($booking, 'refund_status', 'Refund requested', "Your {$booking->currency} ".number_format((float) $quote['refund_amount'], 2).' refund is pending.', ['booking_id' => $booking->id, 'status' => 'pending'], 'refund:'.$booking->id.':pending');
        }
    }

    public function bookingRescheduled(Booking $booking, float $difference): void
    {
        $message = $difference > 0 ? "Complete an additional {$booking->currency} ".number_format($difference, 2).' payment to confirm.' : ($difference < 0 ? "A {$booking->currency} ".number_format(abs($difference), 2).' refund was requested.' : 'No additional payment is required.');
        $this->sendBooking($booking, 'booking_rescheduled', 'Trip rescheduled', "Booking {$booking->reference} was moved to a new trip. {$message}", ['booking_id' => $booking->id, 'fare_difference' => $difference], 'reschedule:'.$booking->id.':'.$booking->updated_at?->timestamp);
    }

    public function seatLockExpired(Booking $booking): void
    {
        $this->sendBooking($booking, 'seat_lock_expiry', 'Seat reservation expired', "The payment window for booking {$booking->reference} expired and its seats were released.", ['booking_id' => $booking->id], 'seat-expiry:'.$booking->id);
    }

    public function seatHoldExpired(User $user, string $token, int $tripId): void
    {
        $this->notifications->send($user, 'seat_lock_expiry', 'Seat reservation expired', 'Your temporary seat reservation expired and the seats are available again.', ['trip_id' => $tripId], null, 'seat-lock:'.$token);
    }

    public function paymentConfirmed(Booking $booking): void
    {
        $this->sendBooking($booking, 'payment_confirmation', 'Payment confirmed', "Payment for booking {$booking->reference} was confirmed.", ['booking_id' => $booking->id, 'amount' => $booking->total, 'currency' => $booking->currency], 'payment-confirmed:'.$booking->id);
    }

    public function refundStatus(Booking $booking, string $status, float $amount): void
    {
        $this->sendBooking($booking, 'refund_status', 'Refund status updated', "The refund for booking {$booking->reference} is now {$status}.", ['booking_id' => $booking->id, 'status' => $status, 'amount' => $amount], 'refund:'.$booking->id.':'.$status);
    }

    public function departureReminders(): int
    {
        $bookings = Booking::where('status', 'confirmed')->whereHas('trip', fn ($trip) => $trip->whereBetween('departs_at', [now()->addHours(23)->addMinutes(50), now()->addHours(24)->addMinutes(10)]))->with('trip')->get();
        foreach ($bookings as $booking) {
            $this->sendBooking($booking, 'departure_reminder', 'Departure reminder', "Booking {$booking->reference} departs at {$booking->trip->departs_at->toDayDateTimeString()}.", ['booking_id' => $booking->id, 'trip_id' => $booking->trip_id], 'departure-reminder:'.$booking->id.':24h');
        }

        return $bookings->count();
    }

    public function tripChanged(Trip $trip): void
    {
        $events = [];
        if ($trip->wasChanged('status') && $trip->status === 'delayed') {
            $events[] = ['trip_delay', 'Trip delayed', 'Your trip has been delayed. Live tracking will show the latest arrival estimate.'];
        }
        if ($trip->wasChanged('status') && $trip->status === 'cancelled') {
            $events[] = ['trip_cancellation', 'Trip cancelled', 'The operator cancelled your trip. Refund processing will follow the applicable policy.'];
        }
        if ($trip->wasChanged('bus_id')) {
            $events[] = ['bus_replacement', 'Bus replacement', 'The operator assigned a replacement bus. Check your booking for updated vehicle details.'];
        }
        if ($trip->wasChanged(['route_id', 'departs_at'])) {
            $events[] = ['boarding_change', 'Boarding details changed', 'Your departure time or boarding route changed. Review the latest trip details.'];
        }
        if ($events === []) {
            return;
        }

        $trip->load('bookings');
        foreach ($trip->bookings->where('status', 'confirmed') as $booking) {
            foreach ($events as [$type, $subject, $body]) {
                $this->sendBooking($booking, $type, $subject, $body, ['booking_id' => $booking->id, 'trip_id' => $trip->id], "trip:{$trip->id}:{$trip->updated_at?->timestamp}:{$type}");
            }
        }
    }

    private function sendBooking(Booking $booking, string $eventType, string $subject, string $body, array $payload, string $key): void
    {
        $booking->loadMissing('user');
        if ($booking->user) {
            $this->notifications->send($booking->user, $eventType, $subject, $body, $payload, null, $key);

            return;
        }
        foreach (array_filter(['email' => $booking->contact_email, 'sms' => $booking->contact_phone]) as $channel => $recipient) {
            $code = $key.':'.$channel;
            if (NotificationRecord::where('code', $code)->exists()) {
                continue;
            }
            $record = NotificationRecord::create(['public_id' => Str::uuid(), 'company_id' => $booking->company_id, 'code' => $code, 'name' => $subject, 'status' => 'queued', 'event_type' => $eventType, 'channel' => $channel, 'subject' => $subject, 'body' => $body, 'recipient' => $recipient, 'data' => $payload, 'scheduled_at' => now()]);
            DeliverPlatformNotification::dispatch($record->id)->afterCommit();
        }
    }
}
