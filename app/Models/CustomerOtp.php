<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CustomerOtp extends Model
{
    use HasUuids;

    public const MAX_ATTEMPTS = 5;
    public const TTL_MINUTES = 10;

    protected $fillable = ['customer_account_id', 'purpose', 'code_hash', 'attempts', 'expires_at', 'consumed_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture() && $this->attempts < self::MAX_ATTEMPTS;
    }
}
