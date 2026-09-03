<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['driver_licence_number' => 'encrypted', 'identity_number' => 'encrypted', 'identity_documents' => 'encrypted:array', 'emergency_contact' => 'encrypted:array', 'data' => 'encrypted:array', 'hired_on' => 'date', 'driver_licence_expires_on' => 'date', 'manifest_access' => 'boolean', 'ticket_scanning_access' => 'boolean', 'rating' => 'decimal:2'];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(StaffAssignment::class);
    }

    public function leaveRecords(): HasMany
    {
        return $this->hasMany(LeaveRecord::class);
    }

    public function trainingRecords(): HasMany
    {
        return $this->hasMany(TrainingRecord::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(StaffReport::class);
    }
}
