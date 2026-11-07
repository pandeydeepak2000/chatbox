<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id',
        'meta', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }
}
