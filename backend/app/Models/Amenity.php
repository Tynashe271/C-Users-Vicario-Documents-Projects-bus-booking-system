<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Amenity extends Model
{
    use SoftDeletes;

    protected $fillable = ['company_id', 'user_id', 'code', 'name', 'status', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
