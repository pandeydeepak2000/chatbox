<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Dashboard notification for one staff member. Marking it read never resolves the underlying work. */
class StaffNotification extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'user_id', 'type', 'title', 'body', 'data', 'read_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
