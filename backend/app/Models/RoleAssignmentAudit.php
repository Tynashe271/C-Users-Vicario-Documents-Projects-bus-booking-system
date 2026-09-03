<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoleAssignmentAudit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['previous_roles' => 'array', 'new_roles' => 'array'];
    }
}
