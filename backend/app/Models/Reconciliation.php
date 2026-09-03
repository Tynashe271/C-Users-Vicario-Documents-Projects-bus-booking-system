<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reconciliation extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expected_amount' => 'decimal:2', 'reported_amount' => 'decimal:2', 'difference_amount' => 'decimal:2', 'data' => 'encrypted:array', 'reconciliation_date' => 'date', 'resolved_at' => 'datetime'];
    }
}
