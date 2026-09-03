<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaintenanceRecord extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'data' => 'encrypted:array', 'parts_used' => 'array', 'starts_at' => 'datetime', 'scheduled_at' => 'datetime', 'completed_at' => 'datetime', 'approved_at' => 'datetime', 'next_service_on' => 'date'];
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }
}
