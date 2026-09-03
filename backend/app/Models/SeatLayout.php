<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeatLayout extends Model
{
    protected $table = 'seat_layout_definitions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['elements' => 'array', 'active' => 'boolean'];
    }
}
