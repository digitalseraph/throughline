<?php

namespace App\Jobs;

use App\Models\Resume;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\CostCeilingExceeded;
use App\Services\Ai\Exceptions\ProviderRateLimited;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class EmbedResume implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $resumeId) {}

    public function handle(AiClient $ai): void
    {
        $resume = Resume::findOrFail($this->resumeId);
        if ($resume->parse_status !== 'parsed') {
            return;
        }

        try {
            $vector = $ai->forUser($resume->user)->embed($resume->parsed_text, 'resume');
        } catch (CostCeilingExceeded $e) {
            $this->release(now()->diffInSeconds(now()->endOfDay()));

            return;
        } catch (ProviderRateLimited $e) {
            $this->release($e->retryAfterSeconds);

            return;
        }

        DB::statement('UPDATE resumes SET embedding = ?::vector WHERE id = ?', [
            '['.implode(',', $vector).']',
            $resume->id,
        ]);

        RecomputeStage1ForResume::dispatch($resume->id);
    }
}
