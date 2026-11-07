<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Real visitor IPs behind a known reverse proxy (e.g. Cloudflare), so per-IP rate limits are
        // not shared by everyone. Only explicitly listed proxy ranges are trusted — never "*".
        $proxies = array_values(array_filter(array_map('trim', explode(',', (string) config('chatbox.trusted_proxies')))));
        if ($proxies !== [] && ! in_array('*', $proxies, true)) {
            \Illuminate\Http\Middleware\TrustProxies::at($proxies);
        }

        // Staff password reset e-mails link back to the dashboard login page (audit C1).
        ResetPassword::createUrlUsing(function ($user, string $token) {
            return url('/login') . '?reset_token=' . urlencode($token) . '&email=' . urlencode($user->getEmailForPasswordReset());
        });

        $tooMany = fn (string $message) => fn () => response()->json(['success' => false, 'message' => $message], 429);

        // Visitor identity is attacker-controlled, so every widget limiter has a per-IP ceiling
        // in addition to the per-visitor limit (audit H6). Ceilings allow for many visitors behind one office NAT.
        $visitorKey = fn (Request $request) => $request->ip() . '|' . substr((string) ($request->input('visitor_token') ?? $request->query('visitor_token') ?? ''), 0, 100);

        // Polling, heartbeat, status, FAQs (one visitor polls ~15-20 times a minute).
        RateLimiter::for('widget', function (Request $request) use ($visitorKey, $tooMany) {
            return [
                Limit::perMinute(60)->by('w:' . $visitorKey($request))->response($tooMany('Too many requests. Please slow down.')),
                Limit::perMinute(1200)->by('w-ip:' . $request->ip())->response($tooMany('Too many requests from your network. Please try again shortly.')),
            ];
        });

        // Widget bootstrap creates visitor rows: keep it tight per IP.
        RateLimiter::for('widget-init', function (Request $request) use ($tooMany) {
            return [
                Limit::perMinute(20)->by('wi:' . $request->ip())->response($tooMany('Too many chat sessions started. Please try again shortly.')),
                Limit::perHour(300)->by('wi-h:' . $request->ip())->response($tooMany('Too many chat sessions started. Please try again later.')),
            ];
        });

        // Typing indicators.
        RateLimiter::for('widget-typing', function (Request $request) use ($visitorKey) {
            return [
                Limit::perMinute(60)->by('wt:' . $visitorKey($request)),
                Limit::perMinute(1200)->by('wt-ip:' . $request->ip()),
            ];
        });

        // Anti-spam visitor messages/uploads (20 per minute per visitor, 120 per IP).
        RateLimiter::for('widget-messages', function (Request $request) use ($visitorKey, $tooMany) {
            $msg = 'Rate limit exceeded: You can send at most 20 messages per minute. Please slow down.';

            return [
                Limit::perMinute(20)->by('wm:' . $visitorKey($request))->response($tooMany($msg)),
                Limit::perMinute(120)->by('wm-ip:' . $request->ip())->response($tooMany($msg)),
            ];
        });

        // Customer Support PIN endpoints (in addition to per-account lockout in CustomerAuthService).
        // Security F5: keys use the lower-cased property key (lookups are case-insensitive, so mixed
        // case must not create fresh buckets) and fall back to the customer session for requests
        // without an e-mail (verify-email), so customers never share one bucket.
        $emailKey = fn (Request $r) => mb_strtolower(trim((string) $r->input('email')));
        $accountKey = fn (Request $r) => ($emailKey($r) !== '' ? $emailKey($r) : 'sess:' . hash('sha256', (string) $r->header('X-Customer-Token')))
            . '|' . mb_strtolower(trim((string) $r->input('property_key')));
        RateLimiter::for('customer-signup', fn (Request $r) => [
            Limit::perHour(5)->by('cs:' . $r->ip())->response($tooMany('Too many sign-up attempts. Please try again later.')),
        ]);
        RateLimiter::for('customer-login', fn (Request $r) => [
            Limit::perMinute(10)->by('cl:' . $r->ip())->response($tooMany('Too many sign-in attempts. Please wait a minute.')),
            Limit::perMinute(10)->by('cl-e:' . $accountKey($r))->response($tooMany('Too many sign-in attempts. Please wait a minute.')),
        ]);
        // Chat sign-in step that tells the widget whether an e-mail already has a PIN: limited
        // more strictly per IP so it cannot be used to check large lists of e-mail addresses.
        RateLimiter::for('customer-identify', fn (Request $r) => [
            Limit::perMinute(10)->by('ci:' . $r->ip())->response($tooMany('Too many attempts. Please wait a minute.')),
            Limit::perHour(40)->by('ci-h:' . $r->ip())->response($tooMany('Too many attempts. Please try again later.')),
        ]);
        RateLimiter::for('otp-request', fn (Request $r) => [
            Limit::perMinutes(15, 3)->by('or-e:' . $accountKey($r))->response($tooMany('Too many code requests. Please wait 15 minutes.')),
            Limit::perDay(8)->by('or-d:' . $accountKey($r))->response($tooMany('Too many code requests today. Please try again tomorrow.')),
            Limit::perHour(10)->by('or:' . $r->ip())->response($tooMany('Too many code requests. Please try again later.')),
        ]);
        RateLimiter::for('otp-verify', fn (Request $r) => [
            Limit::perMinutes(15, 10)->by('ov:' . $r->ip())->response($tooMany('Too many attempts. Please wait 15 minutes.')),
            // Per account across all IPs, so a distributed attack cannot keep guessing codes.
            Limit::perDay(25)->by('ov-a:' . $accountKey($r))->response($tooMany('Too many attempts today. Please try again tomorrow.')),
        ]);

        // Security (logic F3): new conversations per IP and website, so nobody can exhaust a
        // company's monthly chat quota or AI budget by creating visitors in a loop.
        // Security (files F2): uploads per visitor and per IP, on top of the storage quotas.
        RateLimiter::for('widget-uploads', fn (Request $r) => [
            Limit::perHour(15)->by('wu:' . $r->ip() . '|' . (string) $r->input('visitor_token'))->response($tooMany('Upload limit reached. Please try again later.')),
            Limit::perHour(40)->by('wu-ip:' . $r->ip())->response($tooMany('Upload limit reached. Please try again later.')),
        ]);
        RateLimiter::for('widget-start', fn (Request $r) => [
            Limit::perHour(15)->by('ws:' . $r->ip() . '|' . mb_strtolower((string) $r->input('property_key')))->response($tooMany('Too many new chats from your network. Please continue your existing chat or try again later.')),
            Limit::perDay(60)->by('ws-d:' . $r->ip())->response($tooMany('Too many new chats from your network today.')),
        ]);

        // Authenticated staff API.
        RateLimiter::for('staff', function (Request $request) use ($tooMany) {
            return Limit::perMinute(300)->by('s:' . ($request->user()?->id ?: $request->ip()))->response($tooMany('Too many requests. Please slow down.'));
        });

        // General API rate limiter (kept for compatibility).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Authentication endpoints (10 attempts/min per IP; per-account lockout is in AuthController).
        RateLimiter::for('auth-attempts', function (Request $request) use ($tooMany) {
            return Limit::perMinute(10)->by($request->ip())->response($tooMany('Too many attempts. Please wait a minute and try again.'));
        });
    }
}
