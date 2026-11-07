<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

class Attachment extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'property_id', 'conversation_id', 'uploader_type', 'uploader_id',
        'disk', 'path', 'original_name', 'mime', 'size', 'sha256',
    ];

    protected $hidden = ['disk', 'path', 'sha256', 'uploader_id'];

    public static function signedUrlFor(string $id, ?int $ttlMinutes = null): string
    {
        return URL::temporarySignedRoute(
            'attachments.download',
            now()->addMinutes($ttlMinutes ?? (int) config('chatbox.attachments.link_ttl', 1440)),
            ['attachment' => $id]
        );
    }

    public function signedUrl(?int $ttlMinutes = null): string
    {
        return self::signedUrlFor($this->id, $ttlMinutes);
    }

    public function isImage(): bool
    {
        return in_array($this->mime, config('chatbox.attachments.inline_types', []), true);
    }

    /**
     * Extract our attachment id from a URL previously handed out by the upload endpoint.
     * Returns null for anything that is not one of our signed download links.
     */
    public static function idFromUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('#/api/v1/attachments/([0-9a-f\-]{36})$#i', $path, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }
}
