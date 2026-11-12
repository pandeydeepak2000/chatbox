<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AntiBotShield
{
    /**
     * Known malicious automated vulnerability scanner signatures.
     */
    protected array $blacklistedAgents = [
        'sqlmap',
        'nikto',
        'masscan',
        'wpscan',
        'havij',
        'acunetix',
        'dirbuster',
        'nmap',
        'zgrab',
        'morfeus',
    ];

    /**
     * Handle incoming request and block automated bot attacks.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $userAgent = strtolower($request->header('User-Agent', ''));

        // 1. Block known vulnerability scanning bots
        foreach ($this->blacklistedAgents as $badAgent) {
            if (str_contains($userAgent, $badAgent)) {
                Log::warning("AntiBotShield: Malicious scanner blocked [{$badAgent}] from IP: {$request->ip()}");
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied: Automated security scanner detected.',
                ], 403);
            }
        }

        // 1b. Block empty or automated script User-Agents on public widget POST endpoints
        if (! app()->runningUnitTests() && $request->is('api/v1/widget/*') && $request->isMethod('POST')) {
            $scriptBots = ['curl/', 'python-requests', 'python-urllib', 'go-http-client', 'scrapy', 'libwww-perl', 'wget/', 'httpie/'];
            $isScript = trim($userAgent) === '';
            foreach ($scriptBots as $sig) {
                if (str_contains($userAgent, $sig)) {
                    $isScript = true;
                    break;
                }
            }
            if ($isScript) {
                Log::warning("AntiBotShield: Automated script blocked on widget POST from IP: {$request->ip()}");
                return response()->json([
                    'success' => false,
                    'message' => 'Automated requests are not permitted.',
                ], 403);
            }
        }

        // 2. Honeypot Trap Detection (Form autofill bots)
        $honeypotKeys = ['_bot_trap', 'website_hp', 'contact_hp', 'company_fax_hp'];
        foreach ($honeypotKeys as $key) {
            if ($request->filled($key)) {
                Log::warning("AntiBotShield: Honeypot trap triggered by bot on [{$key}] from IP: {$request->ip()}");
                return response()->json([
                    'success' => false,
                    'message' => 'Bot submission rejected.',
                ], 403);
            }
        }

        return $next($request);
    }
}
