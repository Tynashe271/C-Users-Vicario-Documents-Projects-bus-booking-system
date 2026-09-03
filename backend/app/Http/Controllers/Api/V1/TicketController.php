<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\TicketDocumentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TicketController extends Controller
{
    public function pdf(Request $request, Ticket $ticket, TicketDocumentService $documents): Response
    {
        $this->authorizeTicket($request, $ticket);

        return response($documents->pdf($ticket))->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="'.$ticket->ticket_number.'.pdf"');
    }

    public function qr(Request $request, Ticket $ticket, TicketDocumentService $documents): Response
    {
        $this->authorizeTicket($request, $ticket);

        return response($documents->qrSvg($ticket))->header('Content-Type', 'image/svg+xml');
    }

    private function authorizeTicket(Request $request, Ticket $ticket): void
    {
        $ticket->loadMissing('passenger.booking');
        $booking = $ticket->passenger->booking;
        $allowed = $booking->user_id === $request->user()->id || ($booking->company_id === $request->user()->company_id && $request->user()->can('boarding.manage'));
        abort_unless($allowed, 404);
    }
}
