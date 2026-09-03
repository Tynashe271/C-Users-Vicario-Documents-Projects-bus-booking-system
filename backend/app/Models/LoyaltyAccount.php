<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyAccount extends Model
{
    protected $fillable = ['user_id', 'code', 'name', 'status', 'points_balance', 'lifetime_points', 'membership_level', 'referral_code', 'birthday_rewarded_on', 'expiry_alerts_enabled'];

    protected function casts(): array
    {
        return ['birthday_rewarded_on' => 'date', 'expiry_alerts_enabled' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }
}
