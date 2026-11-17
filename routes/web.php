<?php

use App\Models\Property;
use Illuminate\Support\Facades\Route;

// Home Page: ChatBox showcase & live demo (audit B7: no hard-coded key, works on a fresh install).
Route::get('/', function () {
    $pk = (string) config('chatbox.demo_property_key', '');
    if ($pk === '') {
        try {
            $pk = (string) Property::where('is_active', true)->orderBy('created_at')->value('public_key');
        } catch (\Throwable $e) {
            $pk = ''; // database not migrated yet
        }
    }
    $html = file_get_contents(public_path('demo.html'));
    $html = str_replace('__SERVER_PROPERTY_KEY__', e($pk), $html);

    return response($html)->header('Content-Type', 'text/html');
});

// Old sign-in / dashboard addresses now open the new dashboard (query string kept, e.g. the
// password-reset link /login?reset_token=...). The old Blade dashboard stays at /legacy-dashboard.
$toNewDashboard = function (\Illuminate\Http\Request $request) {
    if (! is_file(public_path('app/index.html'))) {
        return view('dashboard'); // static build not uploaded yet
    }
    $qs = $request->getQueryString();

    return redirect('/app' . ($qs ? '?' . $qs : ''));
};
Route::get('/login', $toNewDashboard)->name('login');
Route::get('/register', $toNewDashboard)->name('register');
Route::get('/workspace', $toNewDashboard);
Route::get('/dashboard', $toNewDashboard);
// Off unless CHATBOX_LEGACY_DASHBOARD=true (security: less admin attack surface in production).
Route::get('/legacy-dashboard', fn () => config('chatbox.legacy_dashboard') ? view('dashboard') : abort(404));

Route::get('/demo', function () {
    $property = Property::where('is_active', true)->first();
    $pk = $property ? $property->public_key : '';
    return redirect("/demo.html?pk={$pk}");
});

/*
| Production dashboard (Next.js static export in public/app). Served by Laravel so it works on
| cPanel without a Node.js process. Website-specific admin login: /{slug}/admin.
| Until the static build is uploaded, the Blade dashboard is served as a fallback.
*/
$dashboardSpa = function () {
    $index = public_path('app/index.html');
    if (is_file($index)) {
        return response(file_get_contents($index))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    return view('dashboard');
};
Route::get('/app/{path?}', $dashboardSpa)->where('path', '.*');
Route::get('/{slug}/admin/{path?}', $dashboardSpa)
    ->where('slug', '[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])')
    ->where('path', '.*');

/*
| Company dashboard addresses: https://host/{company} and https://host/{company}/inbox, /tickets, /leads ...
| The same static dashboard as /app, but under the company's own address. Registered LAST so every fixed route
| (/app, /login, /{website}/admin, ...) keeps priority. Only a real company slug and a known view are served; anything
| else is an ordinary 404. The address is presentation only: all data access is still decided by the signed-in user's
| own company on the server (see the sign-in check for tenant_slug in AuthController).
*/
Route::get('/{companySlug}/{view?}/{path?}', function (string $companySlug, ?string $view = null) use ($dashboardSpa) {
    abort_unless(\App\Models\Tenant::where('slug', $companySlug)->exists(), 404);
    request()->attributes->set('chatbox.dashboard', true);   // same security headers / CSP as /app

    return $dashboardSpa();
})->where('companySlug', '[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])')
    ->where('view', implode('|', \App\Models\Tenant::DASHBOARD_VIEWS))
    ->where('path', '.*');
