<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportMessage extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['message' => 'encrypted', 'attachments' => 'encrypted:array', 'data' => 'encrypted:array', 'internal' => 'boolean', 'seen_at' => 'datetime'];
    }
}
