<?php

namespace Tests\Feature\Events;

use App\Events\FeedMatchReranked;
use App\Jobs\RerankShortlist;
use App\Models\CanonicalJob;
use App\Models\JobMatch;
use App\Models\Resume;
use App\Models\SearchProfile;
use App\Models\User;
use App\Services\Ai\AiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class FeedMatchRerankedBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_rerank_shortlist_fires_feed_match_reranked_event_on_success(): void
    {
        Event::fake([FeedMatchReranked::class]);

        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->parsed()->create();
        $profile = SearchProfile::factory()->for($user)->create(['resume_id' => $resume->id]);

        $job = CanonicalJob::factory()->create(['description' => 'Test job']);
        JobMatch::insert([
            'user_id' => $user->id,
            'search_profile_id' => $profile->id,
            'canonical_job_id' => $job->id,
            'stage1_score' => 0.9,
            'stage2_at' => null,
            'created_at' => now(),
        ]);

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('rerank')->andReturn([
            ['job_id' => $job->id, 'fit_score' => 80, 'rationale' => 'Good fit', 'gaps' => [], 'narrative' => 'Strong candidate'],
        ]);
        $this->app->instance(AiClient::class, $ai);

        (new RerankShortlist($profile->id, 20, $user->id))->handle($ai);

        Event::assertDispatched(FeedMatchReranked::class, fn ($e) =>
            $e->userId === $user->id && $e->searchProfileId === $profile->id
        );
        Event::assertDispatchedTimes(FeedMatchReranked::class, 1);
    }
}
