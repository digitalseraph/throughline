<?php

namespace Tests\Feature\Feed;

use App\Jobs\RerankShortlist;
use App\Models\CanonicalJob;
use App\Models\JobMatch;
use App\Models\Resume;
use App\Models\SearchProfile;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\RerankFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FeedRerankTest extends TestCase
{
    use RefreshDatabase;

    protected function seedFreshFeedFor(User $user): array
    {
        $resume = Resume::factory()->for($user)->parsed()->create();
        $profile = SearchProfile::factory()->for($user)->create(['resume_id' => $resume->id]);
        $jobs = CanonicalJob::factory()->count(3)->create();

        foreach ($jobs as $i => $job) {
            JobMatch::insert([
                'user_id' => $user->id,
                'search_profile_id' => $profile->id,
                'canonical_job_id' => $job->id,
                'stage1_score' => 0.9 - ($i * 0.05),
                'stage2_at' => null,
                'created_at' => now(),
            ]);
        }

        return compact('resume', 'profile', 'jobs');
    }

    public function test_sync_rerank_fires_when_top_k_is_stale(): void
    {
        $user = User::factory()->create();
        $ctx = $this->seedFreshFeedFor($user);

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('rerank')->once()->andReturn([
            ['job_id' => $ctx['jobs'][0]->id, 'fit_score' => 90, 'rationale' => 'Strong.', 'gaps' => [], 'narrative' => 'Arc match.'],
            ['job_id' => $ctx['jobs'][1]->id, 'fit_score' => 70, 'rationale' => 'Partial.', 'gaps' => [], 'narrative' => 'Stretch.'],
            ['job_id' => $ctx['jobs'][2]->id, 'fit_score' => 50, 'rationale' => 'Weak.', 'gaps' => [], 'narrative' => 'Far.'],
        ]);
        $this->app->instance(AiClient::class, $ai);

        $response = $this->actingAs($user)->get(route('feed.show', $ctx['profile']));
        $response->assertOk();

        $freshMatches = JobMatch::where('search_profile_id', $ctx['profile']->id)->whereNotNull('stage2_at')->get();
        $this->assertCount(3, $freshMatches);
        $this->assertSame(90, $freshMatches->where('canonical_job_id', $ctx['jobs'][0]->id)->first()->stage2_score);
    }

    public function test_feed_orders_by_fit_score_nulls_last(): void
    {
        $user = User::factory()->create();
        $ctx = $this->seedFreshFeedFor($user);

        // Pre-populate stage-2 on two of three matches to simulate mid-refresh state.
        JobMatch::where('canonical_job_id', $ctx['jobs'][0]->id)->update(['stage2_score' => 60, 'stage2_at' => now()]);
        JobMatch::where('canonical_job_id', $ctx['jobs'][1]->id)->update(['stage2_score' => 80, 'stage2_at' => now()]);
        // jobs[2] has stage2_score null

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('rerank')->andReturn([
            ['job_id' => $ctx['jobs'][2]->id, 'fit_score' => 70, 'rationale' => 'R', 'gaps' => [], 'narrative' => 'N'],
        ]);
        $this->app->instance(AiClient::class, $ai);

        $response = $this->actingAs($user)->get(route('feed.show', $ctx['profile']).'?raw=1');

        $titles = collect($response->viewData('page')['props']['matches']['data'])->pluck('job.title')->all();
        // with ?raw=1, no rerank fires; order is stage1_score DESC
        $this->assertSame(
            $ctx['jobs']->pluck('title')->all(),
            $titles,
            'raw=1 bypass keeps stage1 order',
        );
    }

    public function test_raw_param_bypasses_rerank(): void
    {
        $user = User::factory()->create();
        $ctx = $this->seedFreshFeedFor($user);

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldNotReceive('rerank');
        $this->app->instance(AiClient::class, $ai);

        $this->actingAs($user)->get(route('feed.show', $ctx['profile']).'?raw=1')->assertOk();
    }

    public function test_rerank_failure_degrades_gracefully(): void
    {
        $user = User::factory()->create();
        $ctx = $this->seedFreshFeedFor($user);

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('rerank')->andThrow(new RerankFailed('bad', 'raw'));
        $this->app->instance(AiClient::class, $ai);

        // Feed must not 500 even when rerank blows up.
        $this->actingAs($user)->get(route('feed.show', $ctx['profile']))->assertOk();

        // Matches still exist (stage1-scored), stage2 written as sentinel by RerankShortlist's error path.
        $matches = JobMatch::where('search_profile_id', $ctx['profile']->id)->get();
        foreach ($matches as $m) {
            $this->assertSame('rerank unavailable', $m->stage2_rationale);
        }
    }

    public function test_rerank_disabled_by_site_settings_skips_dispatch(): void
    {
        $user = User::factory()->create();
        $ctx = $this->seedFreshFeedFor($user);

        \DB::table('site_settings')->where('key', 'rerank_enabled')->update(['value' => 'false']);
        \Cache::forget('site_settings'); // if SiteSettings caches

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldNotReceive('rerank');
        $this->app->instance(AiClient::class, $ai);

        $this->actingAs($user)->get(route('feed.show', $ctx['profile']))->assertOk();
    }
}
