<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Knowledge item. Only APPROVED documents (current version) are used for customer-facing answers. */
class KnowledgeDocument extends Model
{
    use HasUuids;

    public const STATUSES = ['draft', 'processing', 'pending_approval', 'approved', 'rejected', 'archived', 'failed'];

    protected $fillable = ['tenant_id', 'property_id', 'title', 'source_type', 'status', 'current_version_id', 'created_by', 'approved_by', 'approved_at', 'review_note'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    /** Company-wide documents + documents of websites the user can access. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $q;
        }
        $q->where('knowledge_documents.tenant_id', $user->tenant_id);
        if (($restricted = $user->restrictedPropertyIds()) !== null) {
            $q->where(fn ($w) => $w->whereNull('knowledge_documents.property_id')->orWhereIn('knowledge_documents.property_id', $restricted));
        }

        return $q;
    }

    public function versions(): HasMany
    {
        return $this->hasMany(KnowledgeVersion::class, 'document_id')->orderByDesc('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(KnowledgeVersion::class, 'current_version_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
