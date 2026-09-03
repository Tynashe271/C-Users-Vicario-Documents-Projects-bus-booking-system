<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trip extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['departs_at' => 'datetime', 'arrives_at' => 'datetime', 'base_fare' => 'decimal:2', 'boarding_started_at' => 'datetime', 'departed_at' => 'datetime', 'arrived_at' => 'datetime', 'completed_at' => 'datetime', 'delayed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function route()
    {
        return $this->belongsTo(TransportRoute::class);
    }

    public function schedule()
    {
        return $this->belongsTo(RecurringSchedule::class);
    }

    public function bus()
    {
        return $this->belongsTo(Bus::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function locations()
    {
        return $this->hasMany(VehicleLocation::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
