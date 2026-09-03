<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bus extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amenities' => 'array', 'images' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(BusDocument::class);
    }

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }

    public function operationalRecords(): HasMany
    {
        return $this->hasMany(BusOperationalRecord::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function seatLayout(): BelongsTo
    {
        return $this->belongsTo(SeatLayout::class);
    }

    public function hasApprovedOperationalDocuments(): bool
    {
        $documents = $this->documents()->whereIn('document_type', ['insurance', 'permit'])->get()->keyBy('document_type');

        return collect(['insurance', 'permit'])->every(function (string $type) use ($documents): bool {
            $document = $documents->get($type);

            return $document?->status === 'approved' && ($document->expires_on === null || $document->expires_on->isFuture());
        });
    }
}
