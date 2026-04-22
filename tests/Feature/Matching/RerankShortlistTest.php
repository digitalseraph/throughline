<?php

namespace Tests\Feature\Matching;

use App\Jobs\RerankShortlist;
use App\Models\CanonicalJob;
use App\Models\JobMatch;
use App\Models\Resume;
use App\Models\SearchProfile;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\CostCeilingExceeded;
use App\Services\Ai\Exceptions\RerankFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RerankShortlistTest extends TestCase
{
    use RefreshDatabase;

    protected function seedProfileWithMatches(int $matchCount, ?int $staleRange = null): array
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->parsed()->create();
        $profile = SearchProfile::factory()->for($user)->create(['resume_id' => $resume->id]);

        $matches = [];
        for ($i = 0; $i < $matchCount; $i++) {
            $job = CanonicalJob::factory()->create(['description' => 'Job #'.$i]);
            $stage2At = $staleRange !== null && $i < $staleRange ? null : now()->subHours(1);
            JobMatch::insert([
                'user_id' => $user->id,
                'search_profile_id' => $profile->id,
                'canonical_job_id' => $job->id,
                'stage1_score' => 0.9 - ($i * 0.01),
                'stage2_at' => $stage2At,
                'created_at' => now(),
            ]);
            $matches[] = ['profile' => $profile, 'job' => $job];
        }

        return ['profile' => $profile, 'user' => $user, 'resume' => $resume, 'matches' => $matches];
    }

    public function test_writes_stage2_columns_on_returned_matches(): void
    {
        $ctx = $this->seedProfileWithMatches(3, staleRange: 3);

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('rerank')->andReturn([
            ['job_id' => $ctx['matches'][0]['job']->id, 'fit_score' => 80, 'rationale' => 'R0', 'gaps' => [], 'narrative' => 'N0'],
            ['job_id' => $ctx['matches'][1]['job']->id, 'fit_score' => 75, 'rationale' => 'R1', 'gaps' => [['requirement' => 'X', 'severity' => 'stretch']], 'narrative' => 'N1'],
            ['job_id' => $ctx['matches'][2]['job']->id, 'fit_score' => 60, 'rationale' => 'R2', 'gaps' => [], 'narrative' => 'N2'],
        ]);
        $this->app->instance(AiClient::class, $ai);

        (new RerankShortlist($ctx['profile']->id, 20, $ctx['user']->id))->handle($ai);

        $match0 = JobMatch::where('canonical_job_id', $ctx['matches'][0]['job']->id)->first();
        $this->assertSame(80, $match0->stage2_score);
        $this->assertSame('R0', $match0->stage2_rationale);
        $this->assertSame('N0', $match0->stage2_narrative);
        $this->assertNotNull($match0->stage2_at);

        $match1 = JobMatch::where('canonical_job_id', $ctx['matches'][1]['job']->id)->first();
        $this->assertEqualsCanonicalizing([['requirement' => 'X', 'severity' => 'stretch']], $match1->stage2_gaps);
    }

    public function test_noop_when_no_stale_matches(): void
    {
        $ctx = $this->seedProfileWithMatches(3, staleRange: 0); // all fresh

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldNotReceive('forUser');
        $this->app->instance(AiClient::class, $ai);

        (new RerankShortlist($ctx['profile']->id, 20, $ctx['user']->id))->handle($ai);
    }

    public function test_handles_fewer_stale_matches_than_shortlist_size(): void
    {
        $ctx = $this->seedProfileWithMatches(5, staleRange: 2); // 2 stale, 3 fresh

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('rerank')
            ->with(Mockery::any(), Mockery::on(fn ($jobs) => count($jobs) === 2), Mockery::any())
            ->andReturn([
                ['job_id' => $ctx['matches'][0]['job']->id, 'fit_score' => 80, 'rationale' => 'R', 'gaps' => [], 'narrative' => 'N'],
                ['job_id' => $ctx['matches'][1]['job']->id, 'fit_score' => 70, 'rationale' => 'R', 'gaps' => [], 'narrative' => 'N'],
            ]);
        $this->app->instance(AiClient::class, $ai);

        (new RerankShortlist($ctx['profile']->id, 20, $ctx['user']->id))->handle($ai);

        $this->assertSame(2, JobMatch::whereNotNull('stage2_at')
            ->whereNotNull('stage2_score')
            ->where('search_profile_id', $ctx['profile']->id)
            ->where('stage2_at', '>', now()->subMinute())
            ->count());
    }

    public function test_writes_sentinel_on_rerank_failed(): void
    {
        $ctx = $this->seedProfileWithMatches(2, staleRange: 2);

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('rerank')->andThrow(new RerankFailed('bad', 'raw'));
        $this->app->instance(AiClient::class, $ai);

        (new RerankShortlist($ctx['profile']->id, 20, $ctx['user']->id))->handle($ai);

        $matches = JobMatch::where('search_profile_id', $ctx['profile']->id)->get();
        foreach ($matches as $m) {
            $this->assertSame('rerank unavailable', $m->stage2_rationale);
            $this->assertNotNull($m->stage2_at);
        }
    }

    public function test_only_updates_matches_for_the_requesting_profile(): void
    {
        // Two users, same canonical job, each with their own job_matches row
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $resumeA = Resume::factory()->for($userA)->parsed()->create();
        $resumeB = Resume::factory()->for($userB)->parsed()->create();
        $profileA = SearchProfile::factory()->for($userA)->create(['resume_id' => $resumeA->id]);
        $profileB = SearchProfile::factory()->for($userB)->create(['resume_id' => $resumeB->id]);
        $job = CanonicalJob::factory()->create();

        $now = now();
        JobMatch::insert([
            ['user_id' => $userA->id, 'search_profile_id' => $profileA->id, 'canonical_job_id' => $job->id, 'stage1_score' => 0.8, 'stage2_score' => null, 'stage2_rationale' => null, 'stage2_gaps' => null, 'stage2_narrative' => null, 'stage2_at' => null, 'created_at' => $now],
            ['user_id' => $userB->id, 'search_profile_id' => $profileB->id, 'canonical_job_id' => $job->id, 'stage1_score' => 0.7, 'stage2_score' => 50, 'stage2_rationale' => 'B-original', 'stage2_gaps' => '[]', 'stage2_narrative' => 'B-original', 'stage2_at' => $now, 'created_at' => $now],
        ]);

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('rerank')->andReturn([
            ['job_id' => $job->id, 'fit_score' => 90, 'rationale' => 'A-new', 'gaps' => [], 'narrative' => 'A-new'],
        ]);
        $this->app->instance(AiClient::class, $ai);

        (new RerankShortlist($profileA->id, 20, $userA->id))->handle($ai);

        $matchA = JobMatch::where('search_profile_id', $profileA->id)->first();
        $this->assertSame(90, $matchA->stage2_score);
        $this->assertSame('A-new', $matchA->stage2_rationale);

        $matchB = JobMatch::where('search_profile_id', $profileB->id)->first();
        $this->assertSame(50, $matchB->stage2_score, 'User B\'s match must not be touched by User A\'s rerank');
        $this->assertSame('B-original', $matchB->stage2_rationale);
    }

    public function test_releases_on_cost_ceiling(): void
    {
        $ctx = $this->seedProfileWithMatches(1, staleRange: 1);

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('rerank')->andThrow(new CostCeilingExceeded($ctx['user']->id, 5.01, 5.0));
        $this->app->instance(AiClient::class, $ai);

        $job = Mockery::mock(RerankShortlist::class.'[release]', [$ctx['profile']->id, 20, $ctx['user']->id]);
        $job->shouldReceive('release')->with(Mockery::on(fn ($secs) => $secs >= 60))->once();

        $job->handle($ai);
    }
}
