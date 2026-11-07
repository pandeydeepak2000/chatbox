<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiBudget extends Model
{
    protected $fillable = ['tenant_id', 'monthly_cap_usd', 'daily_cap_usd', 'per_visitor_daily_messages', 'alert_threshold_pct', 'alert_sent_month', 'limit_sent_month', 'limit_sent_day'];

    protected function casts(): array
    {
        return ['monthly_cap_usd' => 'float', 'daily_cap_usd' => 'float', 'per_visitor_daily_messages' => 'integer', 'alert_threshold_pct' => 'integer', 'reserved_usd' => 'float', 'reserved_until' => 'datetime'];
    }

    public static function forTenant(string $tenantId): self
    {
        // Explicit defaults: a freshly created model must not hold NULL caps (NULL would read as "over budget").
        return self::firstOrCreate(['tenant_id' => $tenantId], [
            'monthly_cap_usd' => 25, 'daily_cap_usd' => 3, 'per_visitor_daily_messages' => 40, 'alert_threshold_pct' => 80,
        ]);
    }
}
