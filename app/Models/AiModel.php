<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A model a company may use with one of its providers. "Saved" never means "available": test_status says that. */
class AiModel extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'provider_id', 'provider_key', 'model_id', 'display_name', 'enabled', 'catalog_status',
        'test_status', 'test_message', 'tested_at', 'input_per_million', 'output_per_million',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'tested_at' => 'datetime', 'input_per_million' => 'float', 'output_per_million' => 'float'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function hasPrice(): bool
    {
        return $this->input_per_million !== null && $this->output_per_million !== null;
    }

    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'model_id' => $this->model_id,
            'display_name' => $this->display_name,
            'enabled' => $this->enabled,
            'catalog_status' => $this->catalog_status,
            'test_status' => $this->test_status,
            'test_message' => $this->test_message,
            'tested_at' => $this->tested_at?->toIso8601String(),
            'input_per_million' => $this->input_per_million,
            'output_per_million' => $this->output_per_million,
            'price_set' => $this->hasPrice(),
        ];
    }
}
