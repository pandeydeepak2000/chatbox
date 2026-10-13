<?php

use App\Models\Property;
use Illuminate\Support\Facades\Route;

// Home Page: ChatBox SaaS Showcase & Live Demo
Route::get('/', function () {
    return response()->file(public_path('demo.html'));
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
