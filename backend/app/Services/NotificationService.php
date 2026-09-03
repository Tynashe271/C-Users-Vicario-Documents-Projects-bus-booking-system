<?php

namespace App\Services;

use App\Jobs\DeliverPlatformNotification;
use App\Models\NotificationRecord;
use App\Models\PlatformResource;
use App\Models\User;
use Illuminate\Support\Str;

class NotificationService
{
    /** @param array<string, mixed> $payload */
    public function send(User $user, string $eventType, string $subject, string $body, array $payload = [], ?array $channels = null, ?string $deduplicationKey = null): array
    {
        $preference = (new PlatformResource)->useModule('notification_preferences')->newQuery()->where('user_id', $user->id)->first();
        $enabled = $channels ?? ($preference?->data['channels'][$eventType] ?? $preference?->data['default_channels'] ?? ['email', 'in_app']);
        $enabled = array_values(array_intersect(array_unique($enabled), ['email', 'sms', 'push', 'whatsapp', 'in_app']));
        $records = [];
        foreach ($enabled as $channel) {
            $code = $deduplicationKey ? $deduplicationKey.':'.$channel : (string) Str::uuid();
            if ($deduplicationKey && NotificationRecord::where('code', $code)->exists()) {
                continue;
            }
            $recipient = match ($channel) {
                'email' => $user->email, 'sms', 'whatsapp' => $user->phone, default => (string) $user->id
            };
            if (blank($recipient)) {
                continue;
            }
            $record = NotificationRecord::create(['public_id' => Str::uuid(), 'company_id' => $user->company_id, 'user_id' => $user->id, 'code' => $code, 'name' => $subject, 'status' => $channel === 'in_app' ? 'sent' : 'queued', 'event_type' => $eventType, 'channel' => $channel, 'subject' => $subject, 'body' => $body, 'recipient' => $recipient, 'data' => $payload, 'scheduled_at' => now(), 'sent_at' => $channel === 'in_app' ? now() : null]);
            if ($channel !== 'in_app') {
                DeliverPlatformNotification::dispatch($record->id)->afterCommit();
            }
            $records[] = $record;
        }

        return $records;
    }
}
