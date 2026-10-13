<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request and attach enterprise security headers.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Handle preflight OPTIONS requests for cross-origin widget integration
        if ($request->isMethod('OPTIONS') && ($request->is('api/*') || $request->is('widget.js'))) {
            return response('', 204)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-Visitor-Token, Accept, Origin')
                ->header('Access-Control-Max-Age', '86400');
        }

        $response = $next($request);

        // Remove server signature
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }

        // Apply strict enterprise security headers
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Allow CORS for public widget & APIs so external client sites can embed widget.js
        if ($request->is('api/v1/widget/*') || $request->is('widget.js') || $request->is('api/*')) {
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-Visitor-Token, Accept, Origin');
        } else {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        return $response;
    }
}
