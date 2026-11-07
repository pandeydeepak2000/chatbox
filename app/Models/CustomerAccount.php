<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Website customer identity (Support PIN). Separate from staff users.
 * Creating an account does NOT prove e-mail ownership: email_verified_at is set only
 * after an e-mailed OTP has been confirmed.
 */
class CustomerAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'property_id', 'email', 'email_normalized', 'pin_hash', 'email_verified_at',
        'failed_attempts', 'lockout_level', 'locked_until', 'session_version', 'pin_changed_at', 'last_login_at',
    ];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'locked_until' => 'datetime',
            'pin_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'failed_attempts' => 'integer',
            'lockout_level' => 'integer',
            'session_version' => 'integer',
        ];
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function isVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CustomerSession::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
