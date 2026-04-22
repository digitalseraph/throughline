<?php

namespace App\Jobs;

use App\Models\CanonicalJob;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\ProviderRateLimited;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class EmbedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $canonicalJobId) {}

    public function handle(AiClient $ai): void
    {
        $job = CanonicalJob::find($this->canonicalJobId);
        if (! $job) {
            return;
        }

        try {
            $vector = $ai->forUser(null)->embed($job->description, 'job');
        } catch (ProviderRateLimited $e) {
            $this->release($e->retryAfterSeconds);

            return;
        }

        DB::statement('UPDATE canonical_jobs SET embedding = ?::vector WHERE id = ?', [
            '['.implode(',', $vector).']',
            $job->id,
        ]);

        FanOutStage1Matches::dispatch($job->id);
    }
}
