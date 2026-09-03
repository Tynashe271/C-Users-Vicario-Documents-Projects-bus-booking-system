<?php

namespace App\Jobs;

use App\Models\NotificationRecord;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class DeliverPlatformNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    /**
     * Create a new job instance.
     */
    public function __construct(public int $notificationId) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $notification = NotificationRecord::findOrFail($this->notificationId);
        if ($notification->status === 'sent') {
            return;
        }
        $notification->increment('attempts');
        if ($notification->channel === 'email') {
            Mail::raw($notification->body, fn ($message) => $message->to($notification->recipient)->subject($notification->subject));
        } else {
            $configuration = config('notification_channels.'.$notification->channel);
            if (blank($configuration['url'] ?? null)) {
                throw new RuntimeException(str($notification->channel)->headline().' provider is not configured.');
            }
            Http::withToken((string) ($configuration['token'] ?? ''))->timeout(15)->retry(2, 250)->post($configuration['url'], ['recipient' => $notification->recipient, 'subject' => $notification->subject, 'message' => $notification->body, 'data' => $notification->data])->throw();
        }
        $notification->update(['status' => 'sent', 'sent_at' => now(), 'failed_at' => null, 'last_error' => null]);
    }

    public function failed(?\Throwable $exception): void
    {
        NotificationRecord::whereKey($this->notificationId)->update(['status' => 'failed', 'failed_at' => now(), 'last_error' => str($exception?->getMessage())->limit(2000)]);
    }
}
