<?php

use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\JobController;
use App\Http\Controllers\Admin\JobSourceController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Applications\ApplicationController;
use App\Http\Controllers\Applications\ContactController;
use App\Http\Controllers\Applications\DocumentController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\ImpersonationStopController;
use App\Http\Controllers\Resumes\ResumeController;
use App\Http\Controllers\SearchProfiles\SearchProfileController;
use App\Http\Middleware\EnsureAdmin;
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

Route::middleware('auth')->group(function () {
    Route::get('/resumes', [ResumeController::class, 'index'])->name('resumes.index');
    Route::post('/resumes', [ResumeController::class, 'store'])->name('resumes.store');
    Route::get('/resumes/{resume}', [ResumeController::class, 'show'])->name('resumes.show');
    Route::patch('/resumes/{resume}', [ResumeController::class, 'update'])->name('resumes.update');
    Route::delete('/resumes/{resume}', [ResumeController::class, 'destroy'])->name('resumes.destroy');
    Route::post('/resumes/{resume}/default', [ResumeController::class, 'setDefault'])->name('resumes.default');
    Route::post('/resumes/{resume}/retry', [ResumeController::class, 'retry'])->name('resumes.retry');

    Route::resource('search-profiles', SearchProfileController::class)
        ->parameters(['search-profiles' => 'profile'])
        ->except(['show']);

    Route::get('/feed/{profile}', [FeedController::class, 'show'])->name('feed.show');

    Route::resource('applications', ApplicationController::class)->except(['create', 'edit']);

    Route::post('/applications/{application}/contacts', [ContactController::class, 'store'])->name('applications.contacts.store');
    Route::patch('/applications/{application}/contacts/{contact}', [ContactController::class, 'update'])->name('applications.contacts.update');
    Route::delete('/applications/{application}/contacts/{contact}', [ContactController::class, 'destroy'])->name('applications.contacts.destroy');

    Route::post('/applications/{application}/documents', [DocumentController::class, 'store'])->name('applications.documents.store');
    Route::delete('/applications/{application}/documents/{document}', [DocumentController::class, 'destroy'])->name('applications.documents.destroy');
});

Route::middleware(['auth', EnsureAdmin::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', [OverviewController::class, 'show'])->name('overview');

        Route::resource('job-sources', JobSourceController::class)->except('show');
        Route::post('job-sources/{job_source}/fetch-now', [JobSourceController::class, 'fetchNow'])->name('job-sources.fetch-now');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');

        Route::post('impersonate/{user}/start', [ImpersonationController::class, 'start'])->name('impersonate.start');

        Route::get('jobs', [JobController::class, 'index'])->name('jobs.index');
        Route::get('jobs/{canonical_job}', [JobController::class, 'show'])->name('jobs.show');

        Route::get('analytics', [AnalyticsController::class, 'show'])->name('analytics');
    });

Route::middleware('auth')->post('/impersonate/stop', ImpersonationStopController::class)->name('admin.impersonate.stop');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
