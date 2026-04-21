<?php

namespace App\Jobs;

use App\Models\CanonicalJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CloseStaleJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        CanonicalJob::whereNull('closed_at')
            ->where('last_seen_at', '<', now()->subDays(30))
            ->update(['closed_at' => now()]);
    }
}
