<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Commission extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['gross_amount' => 'decimal:2', 'platform_amount' => 'decimal:2', 'agent_amount' => 'decimal:2', 'operator_amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'data' => 'encrypted:array', 'available_at' => 'datetime', 'settled_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
