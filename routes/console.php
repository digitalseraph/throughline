<?php

use App\Jobs\CloseStaleJobs;
use App\Jobs\FetchJobSource;
use App\Jobs\NightlyRerankRefresh;
use App\Models\JobSource;
use App\Services\SiteSettings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    JobSource::where('is_enabled', true)
        ->where(function ($q) {
            $q->whereNull('last_fetched_at')
                ->orWhereRaw('last_fetched_at < now() - (fetch_cadence_minutes * interval \'1 minute\')');
        })
        ->each(fn ($source) => FetchJobSource::dispatch($source->id));
})->everyMinute()->name('throughline-ingestion-tick')->withoutOverlapping();

Schedule::job(new CloseStaleJobs)->daily()->name('throughline-close-stale')->withoutOverlapping();

Schedule::call(function () {
    $hour = (int) app(SiteSettings::class)->get('nightly_rerank_hour_utc', '4');
    if (now()->utc()->hour === $hour && now()->utc()->minute === 0) {
        NightlyRerankRefresh::dispatch();
    }
})->everyMinute()->name('throughline-nightly-rerank-gate')->withoutOverlapping();
