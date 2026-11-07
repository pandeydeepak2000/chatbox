<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Message extends Model
{
    use HasFactory, HasUuids;

    /** Stored form of a private attachment reference in `attachment_url`. */
    public const ATTACHMENT_PREFIX = 'attachment:';

    protected $fillable = [
        'conversation_id',
        'ticket_id',
        'sender_type',
        'sender_id',
        'message_type',
        'visibility',
        'is_automated',
        'client_message_id',
        'content',
        'attachment_url',
        'is_read',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'metadata' => 'array',
            'seq' => 'integer',
            'content_html_encoded' => 'boolean',
            'is_automated' => 'boolean',
        ];
    }

    /**
     * Assign a gap-free, strictly increasing per-conversation sequence number.
     * The conversation row is locked for the duration of the insert, so concurrent
     * writers are serialised and pollers using "seq > N" never miss a message (audit B4).
     */
    protected function performInsert(Builder $query)
    {
        return $this->getConnection()->transaction(function () use ($query) {
            if (empty($this->seq) && $this->conversation_id) {
                $conversation = DB::table('conversations')
                    ->where('id', $this->conversation_id)
                    ->lockForUpdate()
                    ->first(['last_seq']);

                $next = ((int) ($conversation->last_seq ?? 0)) + 1;
                DB::table('conversations')->where('id', $this->conversation_id)->update(['last_seq' => $next]);
                $this->seq = $next;
            }

            return parent::performInsert($query);
        });
    }

    /** Rows created before plain-text storage were HTML-encoded; decode them for display. */
    public function getContentAttribute(?string $value): ?string
    {
        if ($value !== null && ! empty($this->attributes['content_html_encoded'])) {
            return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $value;
    }

    /**
     * Private attachments are stored as "attachment:{uuid}" and exposed as a
     * short-lived signed download URL. Legacy rows keep their original URL.
     */
    public function getAttachmentUrlAttribute(?string $value): ?string
    {
        if ($value && str_starts_with($value, self::ATTACHMENT_PREFIX)) {
            return Attachment::signedUrlFor(substr($value, strlen(self::ATTACHMENT_PREFIX)));
        }

        return $value;
    }

    /** Internal staff notes are never returned to the widget. */
    public function scopeVisibleToCustomer(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where('visibility', 'public');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function isFromVisitor(): bool
    {
        return $this->sender_type === 'visitor';
    }

    public function isFromAgent(): bool
    {
        return $this->sender_type === 'agent';
    }

    public function isFromSystem(): bool
    {
        return $this->sender_type === 'system';
    }
}
