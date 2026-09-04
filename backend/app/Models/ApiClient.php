<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApiClient extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** The service account this key authenticates as — every existing permission check just works. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** @return list<string> */
    public function abilities(): array
    {
        return data_get($this->data, 'abilities', ['*']);
    }

    public function hasAbility(string $ability): bool
    {
        $abilities = $this->abilities();

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    /** @return list<string> */
    public function allowedIps(): array
    {
        return data_get($this->data, 'allowed_ips', []);
    }
}
