<?php

namespace App\Services;

use App\Models\Ticket;
use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

class TicketDocumentService
{
    public function qrSvg(Ticket $ticket): string
    {
        return (new SvgWriter)->write(new QrCode(data: $ticket->qr_token, size: 220, margin: 8))->getString();
    }

    public function pdf(Ticket $ticket): string
    {
        $ticket->loadMissing(['passenger.seat', 'passenger.booking.trip.route.origin', 'passenger.booking.trip.route.destination', 'passenger.booking.trip.bus', 'passenger.booking.trip.company']);
        $passenger = $ticket->passenger;
        $booking = $passenger->booking;
        $trip = $booking->trip;
        $paymentStatus = $booking->payments()->latest('id')->value('status') ?? 'unpaid';
        $escape = static fn (mixed $value): string => e((string) $value);
        $qr = base64_encode($this->qrSvg($ticket));
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#172033;padding:28px}.ticket{border:2px solid #172033;padding:24px}.row{margin:8px 0}.label{font-weight:bold}.qr{text-align:right}h1{margin-top:0}</style></head><body><div class="ticket"><h1>'.$escape(config('app.name')).' — Bus Ticket</h1><div class="row"><span class="label">Operator:</span> '.$escape($trip->company->name).'</div><div class="row"><span class="label">Booking:</span> '.$escape($booking->reference).' &nbsp; <span class="label">Ticket:</span> '.$escape($ticket->ticket_number).'</div><div class="row"><span class="label">Passenger:</span> '.$escape($passenger->full_name).' &nbsp; <span class="label">Seat:</span> '.$escape($passenger->seat->number).'</div><div class="row"><span class="label">Route:</span> '.$escape($trip->route->origin->name).' to '.$escape($trip->route->destination->name).'</div><div class="row"><span class="label">Boarding:</span> '.$escape($trip->route->origin->name).'</div><div class="row"><span class="label">Departure:</span> '.$escape($trip->departs_at->toIso8601String()).'</div><div class="row"><span class="label">Bus:</span> '.$escape($trip->bus->model).' ('.$escape($trip->bus->registration_number).')</div><div class="row"><span class="label">Fare:</span> '.$escape($booking->currency).' '.$escape($passenger->fare).' &nbsp; <span class="label">Payment:</span> '.$escape($paymentStatus).'</div><div class="qr"><img width="180" src="data:image/svg+xml;base64,'.$qr.'"></div><p>Present this QR code and identification at boarding. Ticket conditions and luggage limits set by the operator apply.</p></div></body></html>';
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }
}
