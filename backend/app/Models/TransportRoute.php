<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportRoute extends Model
{
    protected $table = 'routes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'border_information' => 'array'];
    }

    public function origin()
    {
        return $this->belongsTo(Terminal::class, 'origin_terminal_id');
    }

    public function destination()
    {
        return $this->belongsTo(Terminal::class, 'destination_terminal_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class, 'route_id')->orderBy('sequence');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'route_id');
    }
}
