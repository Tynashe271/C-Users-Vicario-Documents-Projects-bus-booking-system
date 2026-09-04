<?php

namespace App\Jobs;

use App\Models\PlatformResource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Delivers one queued webhook event to a partner-registered subscription URL, signing the JSON
 * body with the subscription's secret (X-Webhook-Signature: hmac-sha256) so the receiver can
 * verify it actually came from us. Retries follow the same tries/backoff pattern as
 * DeliverPlatformNotification; once tries are exhausted the delivery is marked "exhausted"
 * rather than retried forever.
 */
class DeliverWebhookNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800, 7200];

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $delivery = (new PlatformResource)->useModule('webhook_deliveries')->newQuery()->findOrFail($this->deliveryId);
        if ($delivery->status === 'delivered') {
            return;
        }
        $subscription = (new PlatformResource)->useModule('webhook_subscriptions')->newQuery()->find(data_get($delivery->data, 'subscription_id'));
        if (! $subscription || $subscription->status !== 'active') {
            $delivery->update(['status' => 'cancelled', 'data' => [...($delivery->data ?? []), 'attempts' => $this->attempts()]]);

            return;
        }
        $body = ['event' => data_get($delivery->data, 'event'), 'data' => data_get($delivery->data, 'payload', []), 'delivery_id' => $delivery->id, 'sent_at' => now()->toIso8601String()];
        $signature = hash_hmac('sha256', json_encode($body, JSON_THROW_ON_ERROR), (string) data_get($subscription->data, 'secret'));
        $response = Http::withHeaders(['X-Webhook-Signature' => $signature, 'X-Webhook-Event' => $body['event']])->timeout(10)->post((string) data_get($subscription->data, 'url'), $body);
        $delivery->update(['data' => [...($delivery->data ?? []), 'attempts' => $this->attempts(), 'last_response_code' => $response->status()]]);
        $response->throw();
        $delivery->update(['status' => 'delivered', 'ends_at' => now()]);
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = (new PlatformResource)->useModule('webhook_deliveries')->newQuery()->find($this->deliveryId);
        $delivery?->update(['status' => 'exhausted', 'data' => [...($delivery->data ?? []), 'attempts' => $this->attempts(), 'last_error' => str($exception?->getMessage())->limit(2000)->toString()]]);
    }
}
