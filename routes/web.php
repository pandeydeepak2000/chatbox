<?php

use App\Models\Property;
use Illuminate\Support\Facades\Route;

// Home Page: ChatBox SaaS Showcase & Live Demo
Route::get('/', function () {
    $prop = Property::where('public_key', 'pk_live_chatbox_FiTEeVrRTkfRAuGWIhjD')->first()
        ?? Property::where('is_active', true)->first();
    $pk = $prop ? $prop->public_key : 'pk_live_chatbox_FiTEeVrRTkfRAuGWIhjD';
    $html = file_get_contents(public_path('demo.html'));
    $html = str_replace('__SERVER_PROPERTY_KEY__', $pk, $html);
    return response($html)->header('Content-Type', 'text/html');
});

// Agent Workspace & Sign In Portal
Route::get('/login', function () {
    return view('dashboard');
})->name('login');

Route::get('/workspace', function () {
    return view('dashboard');
});

Route::get('/dashboard', function () {
    return view('dashboard');
});

Route::get('/demo', function () {
    $property = Property::where('is_active', true)->first();
    $pk = $property ? $property->public_key : '';
    return redirect("/demo.html?pk={$pk}");
});
