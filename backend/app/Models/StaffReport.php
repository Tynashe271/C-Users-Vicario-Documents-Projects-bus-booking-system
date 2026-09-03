<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffReport extends Model
{
    protected $fillable = ['company_id', 'employee_id', 'reported_by', 'type', 'occurred_at', 'rating', 'status', 'notes', 'details'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'rating' => 'decimal:2', 'details' => 'array'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
