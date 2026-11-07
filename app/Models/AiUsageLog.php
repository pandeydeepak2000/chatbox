<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'property_id', 'conversation_id', 'provider', 'model', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cost_usd', 'latency_ms', 'outcome', 'error'];

    protected function casts(): array
    {
        return ['cost_usd' => 'float', 'created_at' => 'datetime'];
    }
}
