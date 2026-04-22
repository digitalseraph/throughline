<?php

namespace App\Jobs;

use App\Models\JobMatch;
use App\Models\SearchProfile;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\CostCeilingExceeded;
use App\Services\Ai\Exceptions\ProviderRateLimited;
use App\Services\Ai\Exceptions\RerankFailed;
use App\Services\SiteSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RerankShortlist implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $searchProfileId,
        public int $shortlistSize,
        public ?int $actingUserId = null,
    ) {}

    public function handle(AiClient $ai): void
    {
        $profile = SearchProfile::with('resume')->find($this->searchProfileId);
        if (! $profile || ! $profile->resume || $profile->resume->parse_status !== 'parsed') {
            return;
        }

        $ttlHours = (int) app(SiteSettings::class)->get('stage2_ttl_hours', '24');
        $stalenessCutoff = now()->subHours($ttlHours);

        $staleMatches = DB::table('job_matches')
            ->join('canonical_jobs', 'job_matches.canonical_job_id', '=', 'canonical_jobs.id')
            ->where('job_matches.search_profile_id', $profile->id)
            ->whereNull('job_matches.dismissed_at')
            ->where(fn ($q) => $q->whereNull('job_matches.stage2_at')->orWhere('job_matches.stage2_at', '<', $stalenessCutoff))
            ->orderByDesc('job_matches.stage1_score')
            ->limit($this->shortlistSize)
            ->get([
                'job_matches.id as match_id',
                'canonical_jobs.id as job_id',
                'canonical_jobs.title',
                'canonical_jobs.company_name',
                'canonical_jobs.description',
            ]);

        if ($staleMatches->isEmpty()) {
            return;
        }

        $user = $this->actingUserId ? User::find($this->actingUserId) : null;

        $jobsPayload = $staleMatches->map(fn ($row) => [
            'id' => $row->job_id,
            'title' => $row->title,
            'company' => $row->company_name,
            'description' => $row->description,
        ])->all();

        try {
            $results = $ai->forUser($user)->rerank($profile->resume->parsed_text ?? '', $jobsPayload, $this->searchProfileId);
        } catch (CostCeilingExceeded $e) {
            $seconds = (int) now()->diffInSeconds(now()->endOfDay());
            $this->release(max(60, $seconds));

            return;
        } catch (ProviderRateLimited $e) {
            $this->release($e->retryAfterSeconds);

            return;
        } catch (RerankFailed $e) {
            Log::warning('RerankShortlist RerankFailed', [
                'profile_id' => $profile->id,
                'reason' => $e->reason,
            ]);
            $this->writeSentinel($staleMatches->pluck('match_id')->all());

            return;
        }

        $this->applyResults($results);
    }

    protected function applyResults(array $results): void
    {
        $now = now();
        foreach ($results as $r) {
            JobMatch::where('search_profile_id', $this->searchProfileId)
                ->where('canonical_job_id', $r['job_id'])
                ->update([
                    'stage2_score' => (int) $r['fit_score'],
                    'stage2_rationale' => (string) $r['rationale'],
                    'stage2_gaps' => json_encode($r['gaps']),
                    'stage2_narrative' => (string) $r['narrative'],
                    'stage2_at' => $now,
                ]);
        }
    }

    protected function writeSentinel(array $matchIds): void
    {
        if (empty($matchIds)) {
            return;
        }

        JobMatch::whereIn('id', $matchIds)->update([
            'stage2_rationale' => 'rerank unavailable',
            'stage2_at' => now(),
        ]);
    }
}
