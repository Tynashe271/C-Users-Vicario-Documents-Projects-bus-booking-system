<?php

use App\Models\StaffInvitation;
use App\Services\BookingService;
use App\Services\PassengerJourneyNotificationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('trips:expand --days=90')->dailyAt('00:10')->withoutOverlapping();
Schedule::call(fn () => app(BookingService::class)->releaseExpiredSeatLocks())
    ->name('release-expired-seat-locks')
    ->everyMinute()
    ->withoutOverlapping();
Schedule::call(fn () => app(BookingService::class)->releaseExpiredBookings())
    ->name('release-expired-bookings')
    ->everyMinute()
    ->withoutOverlapping();
Schedule::call(fn () => app(PassengerJourneyNotificationService::class)->departureReminders())
    ->name('send-departure-reminders')
    ->everyTenMinutes()
    ->withoutOverlapping();
Schedule::call(fn () => StaffInvitation::whereNull('accepted_at')->where('expires_at', '<=', now())->delete())
    ->name('remove-expired-staff-invitations')
    ->daily()
    ->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily()->withoutOverlapping();
Schedule::command('fleet:send-document-expiry-warnings --days=30')->dailyAt('07:00')->withoutOverlapping();
