<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Settlement extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['gross_amount' => 'decimal:2', 'platform_fees' => 'decimal:2', 'agent_fees' => 'decimal:2', 'net_amount' => 'decimal:2', 'data' => 'encrypted:array', 'period_start' => 'date', 'period_end' => 'date', 'approved_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SettlementItem::class);
    }
}
