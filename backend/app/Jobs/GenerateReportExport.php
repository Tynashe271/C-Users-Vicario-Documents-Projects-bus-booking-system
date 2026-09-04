<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\Commission;
use App\Models\PlatformResource;
use App\Models\Settlement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Renders a company's (or, for platform staff, the whole platform's) bookings/commissions/
 * settlements for a date range to a CSV file in the background — see ReportExportController.
 * Large exports are exactly the kind of work that shouldn't block an HTTP request; the caller
 * polls the report_exports record for status instead.
 */
class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $exportId)
    {
        $this->onQueue('reports');
    }

    public function handle(): void
    {
        $export = (new PlatformResource)->useModule('report_exports')->newQuery()->findOrFail($this->exportId);
        $export->update(['status' => 'processing']);
        $companyId = $export->company_id;
        $from = data_get($export->data, 'from');
        $to = data_get($export->data, 'to');
        $type = data_get($export->data, 'type');

        [$header, $rows] = match ($type) {
            'bookings' => ['id,reference,status,total,currency,created_at', $this->query(Booking::query(), $companyId, $from, $to)->get()->map(fn (Booking $b) => "{$b->id},{$b->reference},{$b->status},{$b->total},{$b->currency},{$b->created_at?->toIso8601String()}")],
            'commissions' => ['id,code,status,gross_amount,platform_amount,agent_amount,operator_amount,currency,available_at', $this->query(Commission::query(), $companyId, $from, $to, 'available_at')->get()->map(fn (Commission $c) => "{$c->id},{$c->code},{$c->status},{$c->gross_amount},{$c->platform_amount},{$c->agent_amount},{$c->operator_amount},{$c->currency},{$c->available_at?->toIso8601String()}")],
            'settlements' => ['id,code,status,gross_amount,net_amount,currency,period_start,period_end', $this->query(Settlement::query(), $companyId, $from, $to, 'period_start')->get()->map(fn (Settlement $s) => "{$s->id},{$s->code},{$s->status},{$s->gross_amount},{$s->net_amount},{$s->currency},{$s->period_start?->toDateString()},{$s->period_end?->toDateString()}")],
            default => throw new RuntimeException("Unknown report export type '{$type}'."),
        };

        $csv = $header."\n".$rows->implode("\n");
        $path = 'report-exports/'.($companyId ?? 'platform')."/{$type}-{$export->id}.csv";
        Storage::put($path, $csv);
        $export->update(['status' => 'ready', 'data' => [...($export->data ?? []), 'path' => $path, 'row_count' => $rows->count(), 'completed_at' => now()->toIso8601String()]]);
    }

    public function failed(?Throwable $exception): void
    {
        $export = (new PlatformResource)->useModule('report_exports')->newQuery()->find($this->exportId);
        $export?->update(['status' => 'failed', 'data' => [...($export->data ?? []), 'error' => str($exception?->getMessage())->limit(2000)->toString()]]);
    }

    private function query(Builder $query, ?int $companyId, ?string $from, ?string $to, string $dateColumn = 'created_at'): Builder
    {
        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return $query->when($from, fn ($q) => $q->whereDate($dateColumn, '>=', $from))->when($to, fn ($q) => $q->whereDate($dateColumn, '<=', $to));
    }
}
