<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Ticket;
use App\Services\TicketDocumentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GuestTicketController extends Controller
{
    public function __invoke(Request $request, Booking $booking, Ticket $ticket, TicketDocumentService $documents): Response
    {
        abort_unless($request->hasValidSignature(), 403, 'This ticket link is invalid or has expired.');
        abort_unless($ticket->passenger()->where('booking_id', $booking->id)->exists(), 404);
        abort_unless($booking->status === 'confirmed' && $ticket->status === 'active', 409, 'This ticket is not active.');

        return response($documents->pdf($ticket), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="ticket-'.$ticket->ticket_number.'.pdf"',
        ]);
    }
}
