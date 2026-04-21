<?php

use App\Services\Brand\BrandConfig;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function (BrandConfig $brand) {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'siteName' => $brand->name(),
        'tagline' => $brand->tagline(),
    ]);
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard', [
        'user' => ['name' => auth()->user()->name],
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
