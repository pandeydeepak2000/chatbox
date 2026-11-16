<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** "This question must keep finding this approved document." Run without any paid AI call. */
class AiRegressionCase extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'property_id', 'review_id', 'question', 'knowledge_document_id', 'last_result', 'last_run_at'];

    protected function casts(): array
    {
        return ['last_run_at' => 'datetime'];
    }
}
