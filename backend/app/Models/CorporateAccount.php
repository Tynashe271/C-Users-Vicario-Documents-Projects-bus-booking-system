<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CorporateAccount extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'billing_address' => 'array', 'primary_contact' => 'array', 'credit_limit' => 'decimal:2', 'outstanding_balance' => 'decimal:2', 'negotiated_discount_percent' => 'decimal:2', 'verified_at' => 'datetime', 'suspended_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The corporate administrator's login account. */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CorporateMember::class);
    }

    public function costCentres(): HasMany
    {
        return $this->hasMany(CostCentre::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CorporateInvoice::class);
    }

    public function bookingRequests(): HasMany
    {
        return $this->hasMany(CorporateBookingRequest::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function availableCredit(): ?float
    {
        return $this->credit_limit === null ? null : round((float) $this->credit_limit - (float) $this->outstanding_balance, 2);
    }
}
