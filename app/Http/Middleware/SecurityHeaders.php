<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    private const ALLOW_METHODS = 'GET, POST, PUT, DELETE, OPTIONS';
    private const ALLOW_HEADERS = 'Content-Type, Authorization, X-Requested-With, X-Visitor-Token, Accept, Origin';

    /**
     * Security headers + CORS.
     *
     * CORS is split (audit M4): the public widget API is embeddable from any site
     * (each website's domain whitelist is enforced in the controller), while the staff API
     * only answers cross-origin requests from origins listed in CHATBOX_DASHBOARD_ORIGINS.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $corsOrigin = $this->corsOrigin($request);

        if ($request->isMethod('OPTIONS') && ($request->is('api/*') || $request->is('widget.js'))) {
            $response = response('', 204);
            if ($corsOrigin !== null) {
                $response->headers->set('Access-Control-Allow-Origin', $corsOrigin);
                $response->headers->set('Access-Control-Allow-Methods', self::ALLOW_METHODS);
                $response->headers->set('Access-Control-Allow-Headers', self::ALLOW_HEADERS);
                $response->headers->set('Access-Control-Max-Age', '86400');
                if ($corsOrigin !== '*') {
                    $response->headers->set('Vary', 'Origin');
                }
            }

            return $response;
        }

        $response = $next($request);

        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        if ($corsOrigin !== null) {
            $response->headers->set('Access-Control-Allow-Origin', $corsOrigin);
            $response->headers->set('Access-Control-Allow-Methods', self::ALLOW_METHODS);
            $response->headers->set('Access-Control-Allow-Headers', self::ALLOW_HEADERS);
            if ($corsOrigin !== '*') {
                $response->headers->set('Vary', 'Origin');
            }
        }

        if (! $this->isPublicEmbed($request)) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        return $response;
    }

    private function isPublicEmbed(Request $request): bool
    {
        return $request->is('api/v1/widget/*') || $request->is('api/v1/attachments/*') || $request->is('widget.js');
    }

    private function corsOrigin(Request $request): ?string
    {
        if ($this->isPublicEmbed($request)) {
            return '*';
        }

        if ($request->is('api/*')) {
            $origin = $request->headers->get('Origin');
            if ($origin && in_array(rtrim($origin, '/'), config('chatbox.dashboard_origins', []), true)) {
                return $origin;
            }
        }

        return null;
    }
}
