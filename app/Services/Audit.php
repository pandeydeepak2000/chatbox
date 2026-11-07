<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Audit
{
    private const REDACT = ['password', 'new_password', 'token', 'secret', 'secret_key', 'webhook_secret', 'pin', 'otp', 'api_key'];

    /**
     * Record a security-relevant action. Never throws: an audit failure is logged,
     * it must not break the user's request.
     */
    public static function record(string $action, ?Model $subject = null, array $meta = [], ?User $actor = null, ?string $tenantId = null): void
    {
        try {
            $request = request();
            $actor ??= $request?->user();

            AuditLog::create([
                'tenant_id' => $tenantId ?? $actor?->tenant_id ?? ($subject?->getAttribute('tenant_id')),
                'actor_type' => $actor ? 'user' : 'guest',
                'actor_id' => $actor?->id,
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'meta' => self::redact($meta) ?: null,
                'ip_address' => $request?->ip(),
                'user_agent' => mb_substr((string) $request?->userAgent(), 0, 255) ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Audit log write failed: ' . $e->getMessage(), ['action' => $action]);
        }
    }

    private static function redact(array $meta): array
    {
        foreach ($meta as $key => $value) {
            if (is_array($value)) {
                $meta[$key] = self::redact($value);
            } elseif (in_array(strtolower((string) $key), self::REDACT, true)) {
                $meta[$key] = '[redacted]';
            }
        }

        return $meta;
    }
}
