<?php

namespace App\Console\Commands;

use App\Models\BusDocument;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fleet:send-document-expiry-warnings {--days=30}')]
#[Description('Notify fleet managers about bus documents approaching expiry')]
class SendBusDocumentExpiryWarnings extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(NotificationService $notifications): int
    {
        $days = max(1, (int) $this->option('days'));
        $documents = BusDocument::query()->where('status', 'approved')->whereNull('expiry_warning_sent_at')->whereNotNull('expires_on')->whereBetween('expires_on', [today(), today()->addDays($days)])->with('bus')->get();

        foreach ($documents as $document) {
            User::role(['company_owner', 'company_administrator', 'fleet_manager'])->where('company_id', $document->company_id)->get()->each(function (User $user) use ($notifications, $document): void {
                $notifications->send($user, 'bus_document_expiry', 'Bus document expiring', "{$document->name} for {$document->bus?->registration_number} expires on {$document->expires_on->toDateString()}.", ['bus_id' => $document->bus_id, 'document_id' => $document->id], ['in_app', 'email'], "bus-document-expiry-{$document->id}");
            });
            $document->update(['expiry_warning_sent_at' => now()]);
        }

        $this->info("Sent warnings for {$documents->count()} document(s).");

        return self::SUCCESS;
    }
}
