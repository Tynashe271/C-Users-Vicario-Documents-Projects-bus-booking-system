<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $fillable = ['company_id', 'user_id', 'booking_id', 'trip_id', 'code', 'name', 'status', 'amount', 'cleanliness', 'comfort', 'punctuality', 'driver_professionalism', 'customer_service', 'overall_experience', 'comment'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
