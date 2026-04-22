<?php

namespace Tests\Feature\Feed;

use App\Models\CanonicalJob;
use App\Models\JobMatch;
use App\Models\Resume;
use App\Models\SearchProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_lists_matches_in_stage1_score_order(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->parsed()->create();
        $profile = SearchProfile::factory()->for($user)->create(['resume_id' => $resume->id]);

        $jobA = CanonicalJob::factory()->create(['title' => 'A', 'company_name' => 'AAA']);
        $jobB = CanonicalJob::factory()->create(['title' => 'B', 'company_name' => 'BBB']);
        $jobC = CanonicalJob::factory()->create(['title' => 'C', 'company_name' => 'CCC']);

        JobMatch::insert([
            ['user_id' => $user->id, 'search_profile_id' => $profile->id, 'canonical_job_id' => $jobA->id, 'stage1_score' => 0.40, 'created_at' => now()],
            ['user_id' => $user->id, 'search_profile_id' => $profile->id, 'canonical_job_id' => $jobB->id, 'stage1_score' => 0.80, 'created_at' => now()],
            ['user_id' => $user->id, 'search_profile_id' => $profile->id, 'canonical_job_id' => $jobC->id, 'stage1_score' => 0.60, 'created_at' => now()],
        ]);

        $response = $this->actingAs($user)->get(route('feed.show', $profile));

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $titles = collect($props['matches']['data'])->pluck('job.title')->all();
        $this->assertSame(['B', 'C', 'A'], $titles);
    }

    public function test_feed_excludes_dismissed_matches(): void
    {
        $user = User::factory()->create();
        $profile = SearchProfile::factory()->for($user)->create();
        $job = CanonicalJob::factory()->create();
        JobMatch::insert([
            ['user_id' => $user->id, 'search_profile_id' => $profile->id, 'canonical_job_id' => $job->id, 'stage1_score' => 0.5, 'dismissed_at' => now(), 'created_at' => now()],
        ]);

        $response = $this->actingAs($user)->get(route('feed.show', $profile));

        $props = $response->viewData('page')['props'];
        $this->assertCount(0, $props['matches']['data']);
    }

    public function test_feed_forbids_other_users_profiles(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $profile = SearchProfile::factory()->for($owner)->create();

        $this->actingAs($stranger)->get(route('feed.show', $profile))->assertForbidden();
    }
}
