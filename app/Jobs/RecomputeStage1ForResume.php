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

                    (new HardFilterBuilder($profile))->toQuery()
                        ->whereNotNull('embedding')
                        ->chunkById(200, function ($jobs) use ($profile, $resumeEmbedding, $floor) {
                            foreach ($jobs as $job) {
                                $jobEmbedding = DB::table('canonical_jobs')->where('id', $job->id)->value('embedding');
                                if (! $jobEmbedding) {
                                    continue;
                                }

                                $similarity = DB::selectOne(
                                    'SELECT 1 - (?::vector <=> ?::vector) AS s',
                                    [$resumeEmbedding, $jobEmbedding],
                                )->s;

                                if ($similarity < $floor) {
                                    continue;
                                }

                                DB::table('job_matches')->insert([
                                    'user_id' => $profile->user_id,
                                    'search_profile_id' => $profile->id,
                                    'canonical_job_id' => $job->id,
                                    'stage1_score' => $similarity,
                                    'created_at' => now(),
                                ]);
                            }
                        });
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
