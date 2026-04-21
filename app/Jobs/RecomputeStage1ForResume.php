<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecomputeStage1ForResume implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $resumeId) {}

    public function handle(): void
    {
        // Real body lands in Plan 2 Task 12 once job_matches and FanOutStage1Matches exist.
        // For now this job is dispatched by EmbedResume as a no-op so the wiring is in place.
    }
}
