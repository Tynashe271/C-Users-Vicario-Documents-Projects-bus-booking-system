<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Wallet extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['security_pin'];

    protected function casts(): array
    {
        return ['balance' => 'decimal:2', 'available_balance' => 'decimal:2', 'held_balance' => 'decimal:2', 'data' => 'encrypted:array', 'last_transaction_at' => 'datetime', 'is_frozen' => 'boolean', 'security_pin' => 'hashed', 'daily_spend_limit' => 'decimal:2'];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
