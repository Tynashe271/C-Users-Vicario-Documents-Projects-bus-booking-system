<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SettlementItem extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['gross_amount' => 'decimal:2', 'fee_amount' => 'decimal:2', 'net_amount' => 'decimal:2', 'data' => 'encrypted:array'];
    }
}
