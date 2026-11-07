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
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
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

        // Content-Security-Policy for the staff dashboards (security review C-F1/F2): no framing by
        // other sites, scripts/styles/fonts/images only from this server, and API calls only to this
        // server — so even a future XSS bug cannot load outside code or send a token elsewhere.
        if ($this->isDashboardPage($request) && str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $legacy = $request->is('legacy-dashboard') || $request->is('login') || $request->is('dashboard') || $request->is('register') || $request->is('workspace');
            // New dashboard: only its own inline bootstrap scripts are allowed, by hash (recorded at
            // build time in public/app/csp-hashes.json). The legacy page (off by default) still needs
            // 'unsafe-inline' because of its inline onclick handlers.
            $scriptSrc = "script-src 'self' 'unsafe-inline'";
            if (! $legacy && ($hashes = $this->dashboardScriptHashes()) !== []) {
                $scriptSrc = "script-src 'self' " . implode(' ', $hashes);
            }
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                $scriptSrc,
                "style-src 'self' 'unsafe-inline'" . ($legacy ? ' https://fonts.googleapis.com' : ''),
                "font-src 'self' data:" . ($legacy ? ' https://fonts.gstatic.com' : ''),
                "img-src 'self' data: blob:",
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'self'",
            ]));
        }

        return $response;
    }

    /** @return list<string> e.g. ["'sha256-…'"]; empty when the build file is missing or invalid. */
    private function dashboardScriptHashes(): array
    {
        static $hashes = null;
        if ($hashes === null) {
            $file = public_path('app/csp-hashes.json');
            $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $hashes = is_array($decoded)
                ? array_values(array_filter($decoded, fn ($h) => is_string($h) && preg_match("/^'sha256-[A-Za-z0-9+\\/=]{44}'$/", $h)))
                : [];
        }

        return $hashes;
    }

    private function isDashboardPage(Request $request): bool
    {
        return $request->is('app') || $request->is('app/*') || $request->is('*/admin') || $request->is('*/admin/*')
            || $request->is('legacy-dashboard') || $request->is('login') || $request->is('dashboard') || $request->is('register') || $request->is('workspace');
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
