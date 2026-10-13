<?php

namespace App\Providers;

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
        // General API rate limiter (60/min)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Anti-spam visitor message rate limiter (20 messages/min)
        RateLimiter::for('widget-messages', function (Request $request) {
            $key = $request->input('visitor_token') ?: $request->ip();
            return Limit::perMinute(20)->by($key)->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Rate limit exceeded: You can send at most 20 messages per minute. Please slow down.',
                ], 429);
            });
        });

        // Authentication rate limiter (10 attempts/min)
        RateLimiter::for('auth-attempts', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
