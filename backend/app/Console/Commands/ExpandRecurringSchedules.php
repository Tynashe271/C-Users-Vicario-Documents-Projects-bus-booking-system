<?php

namespace App\Console\Commands;

use App\Models\RecurringSchedule;
use App\Services\ScheduleExpansionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('trips:expand {--days=90 : Number of days ahead to generate}')]
#[Description('Expand active recurring schedules into concrete trips')]
class ExpandRecurringSchedules extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ScheduleExpansionService $expansion): int
    {
        $created = 0;
        RecurringSchedule::where('status', 'active')->orderBy('id')->chunkById(100, function ($schedules) use ($expansion, &$created): void {
            foreach ($schedules as $schedule) {
                $created += $expansion->expand($schedule, CarbonImmutable::now()->addDays((int) $this->option('days')));
            }
        });
        $this->info("Created {$created} trips.");

        return self::SUCCESS;
    }
}
