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
Route::get('/legacy-dashboard', fn () => view('dashboard'));

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
