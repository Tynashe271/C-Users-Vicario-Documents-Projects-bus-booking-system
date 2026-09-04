<?php

namespace App\Services;

use App\Jobs\DeliverWebhookNotification;
use App\Models\PlatformResource;
use Illuminate\Support\Str;

/**
 * Fans an internal domain event out to every active partner webhook subscription that asked for
 * it, queuing one DeliverWebhookNotification (with its own retry/backoff) per matching
 * subscription. Call sites (e.g. BookingService::confirmPaidBooking, FinanceController::pay)
 * don't need to know who, if anyone, is listening — an unconfigured company with no
 * subscriptions is a normal, silent no-op, exactly like the other integrations in this app.
 */
class WebhookDispatcher
{
    /** @param array<string, mixed> $payload */
    public function dispatch(string $event, array $payload, ?int $companyId): void
    {
        if (! $companyId) {
            return;
        }
        $subscriptions = (new PlatformResource)->useModule('webhook_subscriptions')->newQuery()
            ->where('company_id', $companyId)->where('status', 'active')->get();
        foreach ($subscriptions as $subscription) {
            $events = data_get($subscription->data, 'events', []);
            if (! in_array($event, $events, true) && ! in_array('*', $events, true)) {
                continue;
            }
            $delivery = (new PlatformResource)->useModule('webhook_deliveries');
            $delivery->fill([
                'company_id' => $companyId, 'code' => 'whd:'.Str::uuid(), 'name' => "{$event} -> {$subscription->name}", 'status' => 'pending',
                'data' => ['subscription_id' => $subscription->id, 'event' => $event, 'payload' => $payload],
            ])->save();
            DeliverWebhookNotification::dispatch($delivery->id);
        }
    }
}
