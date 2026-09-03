<?php

namespace App\Services;

use App\Jobs\DeliverPlatformNotification;
use App\Models\Booking;
use App\Models\NotificationRecord;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class TicketDeliveryService
{
    public function queue(Booking $booking): void
    {
        $booking->loadMissing(['passengers.ticket', 'trip']);
        $expiresAt = $booking->trip->departs_at->copy()->addDay();
        $ticketLines = $booking->passengers->map(function ($passenger) use ($booking, $expiresAt): string {
            $ticket = $passenger->ticket;

            return $passenger->full_name.': '.URL::temporarySignedRoute('guest.tickets.pdf', $expiresAt, [
                'booking' => $booking->public_id,
                'ticket' => $ticket->public_id,
            ]);
        })->implode("\n");
        $body = "Your booking {$booking->reference} is confirmed. Download your ticket(s):\n{$ticketLines}";

        foreach (array_filter(['email' => $booking->contact_email, 'sms' => $booking->contact_phone]) as $channel => $recipient) {
            $notification = NotificationRecord::create([
                'public_id' => Str::uuid(),
                'company_id' => $booking->company_id,
                'user_id' => $booking->user_id,
                'code' => Str::uuid(),
                'name' => 'Your bus ticket',
                'status' => 'queued',
                'event_type' => 'ticket_issued',
                'channel' => $channel,
                'subject' => "Ticket for booking {$booking->reference}",
                'body' => $body,
                'recipient' => $recipient,
                'data' => ['booking_id' => $booking->id],
                'scheduled_at' => now(),
            ]);

            DeliverPlatformNotification::dispatch($notification->id)->afterCommit();
        }
    }
}
