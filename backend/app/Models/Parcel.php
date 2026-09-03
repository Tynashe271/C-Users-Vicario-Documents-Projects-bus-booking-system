<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Parcel extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['collection_code_hash', 'qr_token'];

    protected function casts(): array
    {
        return ['sender_name' => 'encrypted', 'sender_phone' => 'encrypted', 'receiver_name' => 'encrypted', 'receiver_phone' => 'encrypted', 'description' => 'encrypted', 'data' => 'encrypted:array', 'amount' => 'decimal:2', 'weight_kg' => 'decimal:2', 'prohibited_items_declared' => 'boolean', 'collected_at' => 'datetime'];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'route_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ParcelEvent::class);
    }
}
