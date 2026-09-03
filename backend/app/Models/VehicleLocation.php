<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleLocation extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'speed_kph' => 'float', 'accuracy_m' => 'float', 'recorded_at' => 'datetime', 'route_deviation' => 'boolean', 'unexpected_stop' => 'boolean', 'data' => 'encrypted:array'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
