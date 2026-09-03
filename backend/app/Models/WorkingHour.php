<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkingHour extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'clocked_in_at' => 'datetime', 'clocked_out_at' => 'datetime'];
    }
}
