<?php

namespace App\Services;

use App\Models\Booking;
use Dompdf\Dompdf;
use Dompdf\Options;

class ReceiptDocumentService
{
    public function pdf(Booking $booking): string
    {
        $booking->loadMissing(['company:id,name', 'trip.route.origin', 'trip.route.destination', 'passengers.seat', 'payments']);
        $escape = static fn (mixed $value): string => e((string) $value);
        $paid = $booking->payments->where('status', 'paid')->sum('amount');
        $paymentRows = $booking->payments->map(fn ($payment): string => '<tr><td>'.$escape($payment->provider).'</td><td>'.$escape($payment->provider_reference).'</td><td>'.$escape($payment->status).'</td><td>'.$escape($booking->currency).' '.$escape($payment->amount).'</td></tr>')->implode('');
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#172033;padding:28px}h1{margin:0}.muted{color:#64748b}.summary{margin:24px 0;padding:18px;border:1px solid #cbd5e1}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px;border-bottom:1px solid #e2e8f0}.total{font-size:20px;font-weight:bold}</style></head><body><h1>'.$escape(config('app.name')).' Payment Receipt</h1><p class="muted">Booking '.$escape($booking->reference).' · Issued '.$escape(now()->toIso8601String()).'</p><div class="summary"><p><strong>Operator:</strong> '.$escape($booking->company->name).'</p><p><strong>Route:</strong> '.$escape($booking->trip->route->origin->name).' to '.$escape($booking->trip->route->destination->name).'</p><p><strong>Travel:</strong> '.$escape($booking->trip->departs_at->toIso8601String()).'</p><p><strong>Passenger(s):</strong> '.$escape($booking->passengers->pluck('full_name')->implode(', ')).'</p></div><table><thead><tr><th>Method</th><th>Provider reference</th><th>Status</th><th>Amount</th></tr></thead><tbody>'.$paymentRows.'</tbody></table><p class="total">Paid: '.$escape($booking->currency).' '.$escape(number_format((float) $paid, 2, '.', '')).'</p><p>Total booking value: '.$escape($booking->currency).' '.$escape($booking->total).'</p></body></html>';
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }
}
