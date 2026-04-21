<?php

namespace App\Jobs;

use App\Models\CanonicalJob;
use App\Models\SearchProfile;
use App\Services\Matching\HardFilterBuilder;
use App\Services\SiteSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FanOutStage1Matches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $canonicalJobId) {}

    public function handle(): void
    {
        $job = CanonicalJob::find($this->canonicalJobId);
        if (! $job) {
            return;
        }

        $jobEmbedding = DB::table('canonical_jobs')->where('id', $job->id)->value('embedding');
        if (! $jobEmbedding) {
            return; // no embedding yet — EmbedJob will re-dispatch us
        }

        $floor = (float) app(SiteSettings::class)->get('stage1_score_floor', '0.2');

        SearchProfile::where('is_active', true)->whereNotNull('resume_id')->chunkById(100, function ($profiles) use ($job, $jobEmbedding, $floor) {
            foreach ($profiles as $profile) {
                try {
                    $this->fanOutOne($profile, $job, $jobEmbedding, $floor);
                } catch (\Throwable $e) {
                    Log::warning('FanOutStage1Matches profile failure', [
                        'profile_id' => $profile->id,
                        'canonical_job_id' => $job->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    protected function fanOutOne(SearchProfile $profile, CanonicalJob $job, string $jobEmbedding, float $floor): void
    {
        $resumeEmbedding = DB::table('resumes')->where('id', $profile->resume_id)->value('embedding');
        if (! $resumeEmbedding) {
            return;
        }

        $passesHardFilters = (new HardFilterBuilder($profile))
            ->toQuery()
            ->where('id', $job->id)
            ->exists();
        if (! $passesHardFilters) {
            return;
        }

        $similarity = DB::selectOne(
            'SELECT 1 - (?::vector <=> ?::vector) AS s',
            [$resumeEmbedding, $jobEmbedding],
        )->s;

        if ($similarity < $floor) {
            return;
        }

        DB::table('job_matches')->upsert([
            [
                'user_id' => $profile->user_id,
                'search_profile_id' => $profile->id,
                'canonical_job_id' => $job->id,
                'stage1_score' => $similarity,
                'created_at' => now(),
            ],
        ], ['search_profile_id', 'canonical_job_id'], ['stage1_score']);
    }
}
