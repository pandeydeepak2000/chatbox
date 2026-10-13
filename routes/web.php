<?php

use App\Models\Property;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
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
