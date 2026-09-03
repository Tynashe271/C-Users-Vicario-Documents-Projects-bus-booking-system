<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusOperationalRecord extends Model
{
    protected $fillable = ['company_id', 'bus_id', 'branch_id', 'recorded_by', 'type', 'reference', 'occurred_at', 'odometer_km', 'quantity', 'amount', 'currency', 'status', 'notes', 'details'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'amount' => 'decimal:2', 'quantity' => 'decimal:3', 'details' => 'array'];
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }
}
