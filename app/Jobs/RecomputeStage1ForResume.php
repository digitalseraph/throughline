<?php

namespace App\Jobs;

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

class RecomputeStage1ForResume implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $resumeId) {}

    public function handle(): void
    {
        $resumeEmbedding = DB::table('resumes')->where('id', $this->resumeId)->value('embedding');
        if (! $resumeEmbedding) {
            return;
        }

        $floor = (float) app(SiteSettings::class)->get('stage1_score_floor', '0.2');

        SearchProfile::where('is_active', true)->where('resume_id', $this->resumeId)->each(function (SearchProfile $profile) use ($resumeEmbedding, $floor) {
            try {
                DB::transaction(function () use ($profile, $resumeEmbedding, $floor) {
                    DB::table('job_matches')->where('search_profile_id', $profile->id)->delete();

                    // Build a SELECT query from HardFilterBuilder, then insert-from-select.
                    // This runs the cosine computation and floor filter inside the database,
                    // eliminating the previous per-job round-trip for reading the embedding.
                    $selectQuery = (new HardFilterBuilder($profile))->toQuery()
                        ->whereNotNull('embedding')
                        ->whereRaw('(1 - (embedding <=> ?::vector)) >= ?', [$resumeEmbedding, $floor])
                        ->selectRaw(
                            '? as user_id, ? as search_profile_id, id as canonical_job_id, (1 - (embedding <=> ?::vector))::float as stage1_score, NOW() as created_at',
                            [$profile->user_id, $profile->id, $resumeEmbedding]
                        );

                    DB::table('job_matches')->insertUsing(
                        ['user_id', 'search_profile_id', 'canonical_job_id', 'stage1_score', 'created_at'],
                        $selectQuery,
                    );
                });
            } catch (\Throwable $e) {
                Log::warning('RecomputeStage1ForResume profile failure', [
                    'profile_id' => $profile->id,
                    'resume_id' => $this->resumeId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
