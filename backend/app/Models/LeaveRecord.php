<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveRecord extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'starts_on' => 'date', 'ends_on' => 'date', 'approved_at' => 'datetime'];
    }
}
