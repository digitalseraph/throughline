<?php

namespace App\Jobs;

use App\Models\SearchProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NightlyRerankRefresh implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        SearchProfile::where('is_active', true)
            ->whereNotNull('resume_id')
            ->chunkById(100, function ($profiles) {
                foreach ($profiles as $profile) {
                    RerankShortlist::dispatch($profile->id, 50, null);
                }
            });
    }
}
