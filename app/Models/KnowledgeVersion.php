<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeVersion extends Model
{
    use HasUuids;

    protected $fillable = ['document_id', 'version', 'file_path', 'original_name', 'mime', 'size', 'sha256', 'extracted_text', 'status', 'error', 'created_by'];

    protected $hidden = ['file_path'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'document_id');
    }
}
