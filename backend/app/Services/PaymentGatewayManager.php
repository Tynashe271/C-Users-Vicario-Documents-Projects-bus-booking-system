<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\Booking;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentGatewayManager implements PaymentGateway
{
    public function initiate(string $provider, Booking $booking, float $amount, string $idempotencyKey, array $context = []): array
    {
        $configuration = config("payments.providers.$provider");
        if (! is_array($configuration)) {
            throw new RuntimeException('Unsupported payment provider.');
        }
        if ($configuration['demo'] ?? false) {
            return ['provider_reference' => 'DEMO-'.Str::upper(Str::random(12)), 'status' => 'paid', 'instructions' => 'Demo payment completed successfully.'];
        }
        if (blank($configuration['initiate_url'] ?? null)) {
            return ['provider_reference' => 'OFFLINE-'.Str::upper(Str::random(12)), 'status' => 'pending', 'instructions' => $configuration['instructions'] ?? 'Complete payment with the selected provider.'];
        }

        return $this->request($configuration['initiate_url'], $configuration, ['booking_reference' => $booking->reference, 'amount' => $amount, 'currency' => $booking->currency, 'customer' => ['name' => $booking->contact_name, 'email' => $booking->contact_email, 'phone' => $booking->contact_phone], 'callback_url' => route('payments.webhook', ['provider' => $provider]), 'context' => $context], $idempotencyKey);
    }

    public function refund(string $provider, string $providerReference, float $amount, string $idempotencyKey): array
    {
        $configuration = config("payments.providers.$provider");
        if (! is_array($configuration) || blank($configuration['refund_url'] ?? null)) {
            return ['provider_reference' => $providerReference, 'status' => 'pending'];
        }

        $result = $this->request($configuration['refund_url'], $configuration, ['provider_reference' => $providerReference, 'amount' => $amount], $idempotencyKey);

        return ['provider_reference' => $result['provider_reference'], 'status' => $result['status']];
    }

    /** @return array<string, mixed> */
    private function request(string $url, array $configuration, array $payload, string $idempotencyKey): array
    {
        try {
            $response = Http::acceptJson()->withToken((string) ($configuration['api_key'] ?? ''))->withHeaders(['Idempotency-Key' => $idempotencyKey])->timeout(15)->retry(2, 300)->post($url, $payload)->throw();
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Payment provider is temporarily unavailable.', previous: $exception);
        }
        $providerReference = $response->json('reference') ?? $response->json('provider_reference');
        if (! is_string($providerReference)) {
            throw new RuntimeException('Payment provider returned an invalid response.');
        }

        $status = (string) $response->json('status', 'pending');
        if (! in_array($status, ['paid', 'pending', 'failed'], true)) {
            throw new RuntimeException('Payment provider returned an invalid status.');
        }

        return ['provider_reference' => $providerReference, 'status' => $status, 'redirect_url' => $response->json('redirect_url'), 'instructions' => $response->json('instructions')];
    }
}
