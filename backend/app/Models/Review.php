<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'booking_id', 'trip_id', 'code', 'name', 'status', 'amount', 'cleanliness', 'comfort',
        'punctuality', 'driver_professionalism', 'customer_service', 'overall_experience', 'comment',
        'reported_by', 'reported_at', 'report_reason', 'company_response', 'company_responded_at',
        'moderated_by', 'moderated_at', 'moderation_reason',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'reported_at' => 'datetime', 'company_responded_at' => 'datetime', 'moderated_at' => 'datetime'];
    }
}
