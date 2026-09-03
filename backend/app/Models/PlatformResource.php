<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlatformResource extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    public function useModule(string $module): self
    {
        return $this->setTable($module);
    }

    public function dataJson(): ?string
    {
        return $this->data === null ? null : json_encode($this->data, JSON_THROW_ON_ERROR);
    }

    protected function casts(): array
    {
        return [
            'data' => 'encrypted:array',
            'amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
