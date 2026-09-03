<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyApplicationComment extends Model
{
    protected $fillable = ['company_id', 'user_id', 'comment', 'requires_response'];

    protected function casts(): array
    {
        return ['requires_response' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
