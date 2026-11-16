<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A company's private data (chats, tickets, leads, customers, knowledge, AI settings, Telegram, analytics)
 * belongs to that company's staff. The platform super admin manages companies, plans and system health, but
 * is NOT automatically allowed to read their customers' conversations: they sign in with a company account
 * (with that company's consent) if support work is needed. Enforced here on the server, not only in the UI.
 */
class DenyPlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: platform administrators do not have access to a company\'s private data. Sign in with a company account.',
            ], 403);
        }

        return $next($request);
    }
}
