<?php

namespace App\Contracts;

use App\Models\Booking;

interface PaymentGateway
{
    /** @return array{provider_reference:string, status:string, instructions?:mixed, redirect_url?:string} */
    public function initiate(string $provider, Booking $booking, float $amount, string $idempotencyKey, array $context = []): array;

    /** @return array{provider_reference:string, status:string} */
    public function refund(string $provider, string $providerReference, float $amount, string $idempotencyKey): array;
}
