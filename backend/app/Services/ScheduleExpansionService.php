<?php

namespace App\Services;

use App\Models\RecurringSchedule;
use App\Models\TransportRoute;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class ScheduleExpansionService
{
    public function expand(RecurringSchedule $schedule, CarbonImmutable $until): int
    {
        abort_unless($schedule->status === 'active', 409, 'Only active schedules can be expanded.');
        $details = $schedule->data;
        $route = TransportRoute::whereKey($details['route_id'])->where('company_id', $schedule->company_id)->firstOrFail();
        $start = CarbonImmutable::parse($schedule->starts_at)->startOfDay();
        $end = CarbonImmutable::parse($schedule->ends_at ?? $until)->min($until)->endOfDay();
        $created = 0;
        foreach (CarbonPeriod::create($start, $end) as $date) {
            if (! in_array($date->dayOfWeekIso, $details['days_of_week'], true)) {
                continue;
            }
            $departsAt = CarbonImmutable::parse($date->format('Y-m-d').' '.$details['departure_time'], $details['timezone'])->utc();
            $trip = DB::transaction(fn (): Trip => Trip::firstOrCreate(['schedule_id' => $schedule->id, 'departs_at' => $departsAt], ['company_id' => $schedule->company_id, 'route_id' => $route->id, 'bus_id' => $details['bus_id'], 'arrives_at' => $departsAt->addMinutes($route->duration_minutes), 'base_fare' => $schedule->amount, 'currency' => $schedule->currency, 'status' => 'published']));
            $created += $trip->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }
}
