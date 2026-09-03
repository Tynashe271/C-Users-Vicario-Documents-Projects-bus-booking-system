<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    protected $guarded = [];

    public function passengers()
    {
        return $this->hasMany(BookingPassenger::class);
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tickets(): HasManyThrough
    {
        return $this->hasManyThrough(Ticket::class, BookingPassenger::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function corporateAccount()
    {
        return $this->belongsTo(CorporateAccount::class);
    }

    public function costCentre()
    {
        return $this->belongsTo(CostCentre::class);
    }

    public function corporateInvoice()
    {
        return $this->belongsTo(CorporateInvoice::class);
    }

    protected function casts(): array
    {
        return ['fare_breakdown' => 'encrypted:array', 'payable_until' => 'datetime', 'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'taxes' => 'decimal:2', 'fees' => 'decimal:2', 'platform_fee' => 'decimal:2', 'total' => 'decimal:2'];
    }
}
