<?php

namespace App\Services;

use App\Models\PlatformResource;
use App\Models\Settlement;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Pushes a paid settlement to a configured external accounting system (any provider that accepts
 * a JSON POST works — no specific accounting system is assumed). Every attempt is recorded in the
 * integration_logs module regardless of outcome, so staff can see what was synced and retry a
 * failed export without re-deriving the payload.
 */
class AccountingExportService
{
    /** @return array{status: string, settlement_id: int} */
    public function exportSettlement(Settlement $settlement): array
    {
        $payload = [
            'settlement_id' => $settlement->id, 'code' => $settlement->code, 'company_id' => $settlement->company_id,
            'period_start' => $settlement->period_start?->toDateString(), 'period_end' => $settlement->period_end?->toDateString(),
            'gross_amount' => (float) $settlement->gross_amount, 'platform_fees' => (float) $settlement->platform_fees,
            'agent_fees' => (float) $settlement->agent_fees, 'net_amount' => (float) $settlement->net_amount,
            'currency' => $settlement->currency, 'paid_at' => $settlement->paid_at?->toIso8601String(),
        ];
        $config = config('integrations.accounting');
        $status = 'skipped';
        if (filled($config['url'] ?? null)) {
            try {
                Http::withToken((string) ($config['token'] ?? ''))->timeout(15)->post($config['url'], $payload)->throw();
                $status = 'sent';
            } catch (Throwable) {
                $status = 'failed';
            }
        }
        (new PlatformResource)->useModule('integration_logs')->fill([
            'company_id' => $settlement->company_id, 'code' => 'accounting-export:'.$settlement->id.':'.now()->format('YmdHisu'), 'name' => 'Accounting export: settlement '.$settlement->code, 'status' => $status,
            'data' => ['type' => 'accounting', 'settlement_id' => $settlement->id, 'payload' => $payload],
        ])->save();

        return ['status' => $status, 'settlement_id' => $settlement->id];
    }
}
