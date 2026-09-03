<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NotificationRecord extends Model
{
    use SoftDeletes;

    protected $table = 'notifications';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'recipient' => 'encrypted', 'data' => 'encrypted:array', 'scheduled_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime', 'read_at' => 'datetime'];
    }
}
