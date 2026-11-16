<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** An administrator's verdict on one automated answer (stars, incorrect flag, proposed correction). */
class AiAnswerReview extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'property_id', 'conversation_id', 'message_id', 'reviewer_id', 'rating', 'is_incorrect',
        'question', 'answer_excerpt', 'correction_text', 'correction_status', 'decided_by', 'decided_at', 'knowledge_document_id',
    ];

    protected function casts(): array
    {
        return ['is_incorrect' => 'boolean', 'rating' => 'integer', 'decided_at' => 'datetime'];
    }
}
