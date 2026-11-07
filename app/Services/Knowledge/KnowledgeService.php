<?php

namespace App\Services\Knowledge;

use App\Jobs\ProcessKnowledgeVersion;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeVersion;
use App\Models\User;
use App\Services\Audit;
use App\Support\SafeText;
use App\Support\SearchText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Knowledge pipeline: upload/manual text -> extract (queued) -> pending_approval -> approve -> index.
 * Only approved documents' CURRENT version is searchable. Older versions stay for rollback.
 */
class KnowledgeService
{
    public const CHUNK_CHARS = 900;
    public const CHUNK_OVERLAP = 120;

    public static function createManual(User $user, ?string $propertyId, string $title, string $text, string $sourceType = 'manual'): KnowledgeDocument
    {
        \App\Services\PlanLimits::assertCanAdd($user->tenant_id, 'knowledge_documents');
        $text = SafeText::message($text);
        if ($text === '') {
            throw ValidationException::withMessages(['content' => ['Content cannot be empty.']]);
        }

        return DB::transaction(function () use ($user, $propertyId, $title, $text, $sourceType) {
            $doc = KnowledgeDocument::create([
                'tenant_id' => $user->tenant_id,
                'property_id' => $propertyId,
                'title' => mb_substr($title, 0, 200),
                'source_type' => $sourceType,
                'status' => 'pending_approval',
                'created_by' => $user->id,
            ]);
            KnowledgeVersion::create([
                'document_id' => $doc->id,
                'version' => 1,
                'extracted_text' => $text,
                'status' => 'ready',
                'created_by' => $user->id,
            ]);
            Audit::record('knowledge.created', $doc, ['source' => $sourceType]);

            return $doc;
        });
    }

    /** New document from a file, or a new version of an existing document. */
    public static function upload(User $user, UploadedFile $file, ?string $propertyId, ?string $title, ?KnowledgeDocument $existing = null): KnowledgeDocument
    {
        if ($file->getSize() > DocumentExtractor::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => ['The file must be 10 MB or smaller.']]);
        }
        if (! $existing) {
            \App\Services\PlanLimits::assertCanAdd($user->tenant_id, 'knowledge_documents');
        }
        $kind = DocumentExtractor::detectKind($file->getRealPath(), $file->getClientOriginalName());
        if (! $kind) {
            throw ValidationException::withMessages(['file' => ['Unsupported file. Upload TXT, CSV, XLSX, PDF or DOCX.']]);
        }

        $tenantId = $existing?->tenant_id ?? $user->tenant_id;
        $path = 'knowledge/' . $tenantId . '/' . Str::uuid() . '.' . $kind;
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        [$doc, $version] = DB::transaction(function () use ($user, $file, $propertyId, $title, $existing, $path, $kind, $tenantId) {
            $doc = $existing ?? KnowledgeDocument::create([
                'tenant_id' => $tenantId,
                'property_id' => $propertyId,
                'title' => mb_substr($title ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 0, 200),
                'source_type' => 'file',
                'status' => 'processing',
                'created_by' => $user->id,
            ]);
            $next = (int) KnowledgeVersion::where('document_id', $doc->id)->max('version') + 1;
            $version = KnowledgeVersion::create([
                'document_id' => $doc->id,
                'version' => $next,
                'file_path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                'mime' => $kind,
                'size' => (int) $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'status' => 'processing',
                'created_by' => $user->id,
            ]);
            if ($existing && $existing->status !== 'approved') {
                $existing->forceFill(['status' => 'processing'])->save();
            }

            return [$doc, $version];
        });

        Audit::record('knowledge.uploaded', $doc, ['version' => $version->version, 'type' => $kind]);
        ProcessKnowledgeVersion::dispatch($version->id);

        return $doc->fresh();
    }

    /** Runs in the queue (cron worker on cPanel). */
    public static function process(KnowledgeVersion $version): void
    {
        $doc = $version->document;
        try {
            $full = Storage::disk('local')->path($version->file_path);
            $text = DocumentExtractor::extract($full, $version->mime);
            $version->forceFill(['extracted_text' => $text, 'status' => 'ready', 'error' => null])->save();
            if ($doc->status !== 'approved') {
                $doc->forceFill(['status' => 'pending_approval'])->save();
            }
        } catch (\Throwable $e) {
            $version->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
            if ($doc->status !== 'approved') {
                $doc->forceFill(['status' => 'failed'])->save();
            }
        }
    }

    /** Approve the newest ready version (optionally with reviewer-edited text) and index it. */
    public static function approve(KnowledgeDocument $doc, User $user, ?string $editedText = null): KnowledgeDocument
    {
        $version = KnowledgeVersion::where('document_id', $doc->id)->where('status', 'ready')->orderByDesc('version')->first();
        if (! $version) {
            throw ValidationException::withMessages(['document' => ['There is no processed version to approve yet.']]);
        }
        if ($editedText !== null && trim($editedText) !== '') {
            $version->forceFill(['extracted_text' => SafeText::message($editedText)])->save();
        }

        DB::transaction(function () use ($doc, $version, $user) {
            KnowledgeChunk::where('document_id', $doc->id)->delete();
            foreach (self::chunk((string) $version->extracted_text) as $i => $content) {
                KnowledgeChunk::create([
                    'tenant_id' => $doc->tenant_id,
                    'property_id' => $doc->property_id,
                    'document_id' => $doc->id,
                    'version_id' => $version->id,
                    'ordinal' => $i,
                    'content' => $content,
                    'keywords' => SearchText::keywordString($doc->title . ' ' . $content),
                ]);
            }
            $doc->forceFill([
                'status' => 'approved',
                'current_version_id' => $version->id,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'review_note' => null,
            ])->save();
        });
        Audit::record('knowledge.approved', $doc, ['version' => $version->version]);

        return $doc->fresh();
    }

    public static function reject(KnowledgeDocument $doc, User $user, ?string $note): KnowledgeDocument
    {
        // A previously approved version stays live; only the new version is rejected.
        if ($doc->current_version_id) {
            KnowledgeVersion::where('document_id', $doc->id)->where('id', '!=', $doc->current_version_id)
                ->where('status', 'ready')->update(['status' => 'failed', 'error' => 'Rejected by reviewer']);
            $doc->forceFill(['status' => 'approved', 'review_note' => mb_substr((string) $note, 0, 500)])->save();
        } else {
            $doc->forceFill(['status' => 'rejected', 'review_note' => mb_substr((string) $note, 0, 500)])->save();
        }
        Audit::record('knowledge.rejected', $doc, ['note' => $note]);

        return $doc;
    }

    public static function archive(KnowledgeDocument $doc): void
    {
        KnowledgeChunk::where('document_id', $doc->id)->delete();
        $doc->forceFill(['status' => 'archived'])->save();
        Audit::record('knowledge.archived', $doc);
    }

    /** @return array<int, string> */
    public static function chunk(string $text): array
    {
        $paragraphs = preg_split("/\n\s*\n/u", trim($text)) ?: [];
        $chunks = [];
        $buf = '';
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            while (mb_strlen($p) > self::CHUNK_CHARS) { // very long paragraph: hard split on sentence/space
                $cut = mb_strrpos(mb_substr($p, 0, self::CHUNK_CHARS), '. ') ?: mb_strrpos(mb_substr($p, 0, self::CHUNK_CHARS), ' ') ?: self::CHUNK_CHARS;
                $chunks[] = trim(($buf !== '' ? $buf . "\n" : '') . mb_substr($p, 0, $cut + 1));
                $buf = '';
                $p = trim(mb_substr($p, max(1, $cut + 1 - self::CHUNK_OVERLAP)));
            }
            if (mb_strlen($buf) + mb_strlen($p) + 1 > self::CHUNK_CHARS && $buf !== '') {
                $chunks[] = $buf;
                $buf = mb_substr($buf, -self::CHUNK_OVERLAP) . "\n" . $p;
            } else {
                $buf = $buf === '' ? $p : $buf . "\n\n" . $p;
            }
        }
        if (trim($buf) !== '') {
            $chunks[] = trim($buf);
        }

        return array_slice($chunks, 0, 3000);
    }
}
