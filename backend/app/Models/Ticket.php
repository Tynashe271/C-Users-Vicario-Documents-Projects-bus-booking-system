<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    protected $guarded = [];

    protected $hidden = ['qr_token'];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime', 'boarded_at' => 'datetime', 'absent_at' => 'datetime'];
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(BookingPassenger::class, 'booking_passenger_id');
    }
}
