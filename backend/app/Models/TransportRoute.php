<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportRoute extends Model
{
    protected $table = 'routes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'border_information' => 'array', 'commission_tiers' => 'array'];
    }

    public function origin()
    {
        return $this->belongsTo(Terminal::class, 'origin_terminal_id');
    }

    public function destination()
    {
        return $this->belongsTo(Terminal::class, 'destination_terminal_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class, 'route_id')->orderBy('sequence');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'route_id');
    }

    /**
     * The commission rate for a fare of $amount on this route — whichever configured tier it falls
     * into (min_amount <= amount < max_amount, max_amount null = unbounded; overlapping tiers are
     * the caller's mistake to avoid, resolved by picking the lowest-min_amount match). No tiers
     * configured, or none matching, falls back to $fallbackRatePercent (the operator's flat
     * company-wide commission_rate), so a route is never accidentally commission-free.
     */
    public function commissionRate(float $amount, float $fallbackRatePercent): float
    {
        $tiers = collect($this->commission_tiers ?? [])->sortBy('min_amount')->values();
        $tier = $tiers->first(function (array $tier) use ($amount): bool {
            $max = $tier['max_amount'] ?? null;

            return $amount >= (float) ($tier['min_amount'] ?? 0) && ($max === null || $amount < (float) $max);
        });

        return (float) ($tier['rate_percent'] ?? $fallbackRatePercent);
    }
}
