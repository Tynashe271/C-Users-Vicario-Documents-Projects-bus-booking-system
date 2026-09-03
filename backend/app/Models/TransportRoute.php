<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransportRoute extends Model
{
    protected $table = 'routes';

    protected $guarded = [];

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
}
