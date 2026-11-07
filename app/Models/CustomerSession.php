<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'customer_account_id', 'property_id', 'token_hash', 'session_version', 'expires_at',
        'last_used_at', 'revoked_at', 'ip_address', 'user_agent',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'session_version' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }
}
