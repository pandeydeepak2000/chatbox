<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AttachmentController extends Controller
{
    /**
     * Serve a private attachment. The route is protected by the `signed` middleware:
     * links are only produced inside authorised API responses and expire.
     */
    public function download(string $attachment): Response
    {
        $file = Attachment::findOrFail($attachment);
        $disk = Storage::disk($file->disk);
        abort_unless($disk->exists($file->path), 404);

        $headers = [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=3600',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ];

        $disposition = $file->isImage() ? 'inline' : 'attachment';

        return $disk->response($file->path, $file->original_name, $headers, $disposition);
    }
}
