<?php

namespace Tests\Feature\Matching;

use App\Jobs\NightlyRerankRefresh;
use App\Jobs\RerankShortlist;
use App\Models\Resume;
use App\Models\SearchProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NightlyRerankRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_rerank_per_active_profile_with_resume(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $resumeA = Resume::factory()->for($user)->parsed()->create();
        $resumeB = Resume::factory()->for($user)->parsed()->create();
        $profileActive = SearchProfile::factory()->for($user)->create(['resume_id' => $resumeA->id, 'is_active' => true]);
        $profileInactive = SearchProfile::factory()->for($user)->create(['resume_id' => $resumeB->id, 'is_active' => false]);
        $profileNoResume = SearchProfile::factory()->for($user)->create(['resume_id' => null, 'is_active' => true]);

        (new NightlyRerankRefresh)->handle();

        Queue::assertPushed(RerankShortlist::class, 1);
        Queue::assertPushed(RerankShortlist::class, fn ($job) => $job->searchProfileId === $profileActive->id && $job->shortlistSize === 50 && $job->actingUserId === null);
    }
}
