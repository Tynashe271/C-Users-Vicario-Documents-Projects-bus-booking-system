<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $guarded = [];

    protected $hidden = ['bank_details'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'registration_information' => 'array', 'business_address' => 'array', 'contact_people' => 'array', 'bank_details' => 'encrypted:array', 'support_information' => 'array', 'booking_policy' => 'array', 'cancellation_policy' => 'array', 'social_links' => 'array', 'rescheduling_policy' => 'array', 'luggage_policy' => 'array', 'boarding_policy' => 'array', 'notification_templates' => 'array', 'ticket_design' => 'array', 'settlement_information' => 'encrypted:array', 'submitted_at' => 'datetime', 'verified_at' => 'datetime', 'suspended_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
