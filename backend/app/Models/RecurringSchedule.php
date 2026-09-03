<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecurringSchedule extends Model
{
    protected $table = 'schedules';

    protected $fillable = ['company_id', 'user_id', 'code', 'name', 'status', 'amount', 'currency', 'data', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'amount' => 'decimal:2', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
