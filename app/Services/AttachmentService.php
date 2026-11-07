<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Secure upload handling (audit C4 / H9):
 * - type is decided by sniffing the file contents (finfo), never by the client name or header
 * - only an allow-list of types; SVG / HTML / scripts are rejected
 * - stored extension comes from the sniffed type
 * - files live on the private disk and are served through signed, expiring links
 */
class AttachmentService
{
    public static function store(UploadedFile $file, string $tenantId, ?string $propertyId, string $uploaderType, string $uploaderId, ?string $conversationId = null): Attachment
    {
        // Security (files F2): anonymous visitors cannot fill the disk. Per visitor and per website
        // per day limits; files never sent in a message are deleted by chatbox:maintenance.
        if ($uploaderType === 'visitor') {
            $today = Attachment::where('uploader_type', 'visitor')->where('created_at', '>=', now()->subDay());
            if ((clone $today)->where('uploader_id', $uploaderId)->count() >= (int) config('chatbox.attachments.visitor_daily_files', 20)) {
                throw ValidationException::withMessages(['file' => ['You have reached today’s upload limit. Please describe the problem in a message instead.']]);
            }
            $quota = (int) config('chatbox.attachments.website_daily_mb', 300) * 1024 * 1024;
            if ($propertyId && (int) (clone $today)->where('property_id', $propertyId)->sum('size') + (int) $file->getSize() > $quota) {
                throw ValidationException::withMessages(['file' => ['Uploads are temporarily unavailable. Please try again tomorrow.']]);
            }
        }

        $allowed = config('chatbox.attachments.allowed', []);
        $mime = self::sniff($file->getRealPath());

        if (! isset($allowed[$mime])) {
            throw ValidationException::withMessages([
                'file' => ['This file type is not allowed. Allowed: images (JPG, PNG, GIF, WEBP), PDF, TXT, DOC, DOCX, ZIP.'],
            ]);
        }

        $ext = $allowed[$mime];
        $dir = 'attachments/' . $tenantId . '/' . now()->format('Y/m');
        $name = Str::uuid()->toString() . '.' . $ext;
        Storage::disk('local')->putFileAs($dir, $file, $name);

        return Attachment::create([
            'tenant_id' => $tenantId,
            'property_id' => $propertyId,
            'conversation_id' => $conversationId,
            'uploader_type' => $uploaderType,
            'uploader_id' => $uploaderId,
            'disk' => 'local',
            'path' => $dir . '/' . $name,
            'original_name' => self::cleanName($file->getClientOriginalName(), $ext),
            'mime' => $mime,
            'size' => (int) $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
        ]);
    }

    /**
     * Resolve an attachment reference sent with a chat message and make sure the
     * sender is allowed to use it in this conversation. Returns [storedValue, messageType].
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function resolveForMessage(?string $attachmentId, ?string $attachmentUrl, Conversation $conversation, string $senderType, string $senderId): array
    {
        $id = $attachmentId ?: Attachment::idFromUrl($attachmentUrl);
        if (! $id) {
            if (! empty($attachmentUrl)) {
                throw ValidationException::withMessages(['attachment_url' => ['Attachments must be uploaded through ChatBox first.']]);
            }

            return [null, null];
        }

        $attachment = Attachment::where('id', $id)
            ->where('tenant_id', $conversation->property->tenant_id)
            ->where('uploader_type', $senderType)
            ->where('uploader_id', $senderId)
            ->where(fn ($q) => $q->whereNull('conversation_id')->orWhere('conversation_id', $conversation->id))
            ->first();

        if (! $attachment || ($attachment->property_id && $attachment->property_id !== $conversation->property_id)) {
            throw ValidationException::withMessages(['attachment_url' => ['Attachment not found.']]);
        }

        $attachment->update(['conversation_id' => $conversation->id, 'property_id' => $conversation->property_id]);

        return [Message::ATTACHMENT_PREFIX . $attachment->id, $attachment->isImage() ? 'image' : 'file'];
    }

    public static function sniff(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($path);

        // Office Open XML is sometimes reported as a generic zip; only accept it as DOCX
        // when the archive really contains a Word document part.
        if (in_array($mime, ['application/zip', 'application/octet-stream'], true) && class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($path) === true) {
                $isDocx = $zip->locateName('word/document.xml') !== false;
                $zip->close();
                if ($isDocx) {
                    return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                }
            }
        }

        return $mime;
    }

    private static function cleanName(string $name, string $ext): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[^\pL\pN _.\-()]+/u', '_', $base) ?? 'file';
        $base = trim(mb_substr($base, 0, 150), ' ._') ?: 'file';

        return $base . '.' . $ext;
    }
}
