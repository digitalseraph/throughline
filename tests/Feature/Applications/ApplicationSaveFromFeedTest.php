<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\CanonicalJob;
use App\Models\Resume;
use App\Models\SearchProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationSaveFromFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function seedMatchFor(User $user, array $matchOverrides = []): array
    {
        $resume = Resume::factory()->for($user)->parsed()->create();
        $profile = SearchProfile::factory()->for($user)->create(['resume_id' => $resume->id]);
        $job = CanonicalJob::factory()->create();

        $matchId = \DB::table('job_matches')->insertGetId(array_merge([
            'user_id' => $user->id,
            'search_profile_id' => $profile->id,
            'canonical_job_id' => $job->id,
            'stage1_score' => 0.8,
            'stage2_score' => 85,
            'stage2_rationale' => 'Strong fit.',
            'stage2_gaps' => json_encode([['requirement' => 'Go', 'severity' => 'stretch']]),
            'stage2_narrative' => 'Natural next step.',
            'stage2_at' => now(),
            'created_at' => now(),
        ], $matchOverrides));

        return compact('resume', 'profile', 'job', 'matchId');
    }

    public function test_save_creates_application_with_stage2_snapshot_and_status_change_event(): void
    {
        $user = User::factory()->create();
        $ctx = $this->seedMatchFor($user);

        $this->actingAs($user)
            ->post(route('applications.store'), ['job_match_id' => $ctx['matchId']])
            ->assertRedirect()
            ->assertSessionHas('status');

        $app = Application::first();
        $this->assertSame($user->id, $app->user_id);
        $this->assertSame($ctx['job']->id, $app->canonical_job_id);
        $this->assertSame($ctx['profile']->id, $app->search_profile_id);
        $this->assertSame($ctx['resume']->id, $app->resume_id);
        $this->assertSame('saved', $app->status);

        $this->assertSame(85, $app->stage2_snapshot['fit_score']);
        $this->assertSame('Strong fit.', $app->stage2_snapshot['rationale']);

        $this->assertSame(1, $app->events()->count());
        $event = $app->events()->first();
        $this->assertSame('status_change', $event->type);
        $this->assertEquals(['from' => null, 'to' => 'saved'], $event->payload);
    }

    public function test_duplicate_save_returns_409_with_existing_id(): void
    {
        $user = User::factory()->create();
        $ctx = $this->seedMatchFor($user);

        $this->actingAs($user)->post(route('applications.store'), ['job_match_id' => $ctx['matchId']])->assertRedirect();

        $response = $this->actingAs($user)->post(route('applications.store'), ['job_match_id' => $ctx['matchId']]);
        $response->assertStatus(409);
    }

    public function test_save_requires_resume_on_profile(): void
    {
        $user = User::factory()->create();
        $profile = SearchProfile::factory()->for($user)->create(['resume_id' => null]);
        $job = CanonicalJob::factory()->create();

        $matchId = \DB::table('job_matches')->insertGetId([
            'user_id' => $user->id,
            'search_profile_id' => $profile->id,
            'canonical_job_id' => $job->id,
            'stage1_score' => 0.8,
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('applications.store'), ['job_match_id' => $matchId])
            ->assertStatus(422);
    }

    public function test_stranger_cannot_save_anothers_match(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $ctx = $this->seedMatchFor($owner);

        $this->actingAs($stranger)
            ->post(route('applications.store'), ['job_match_id' => $ctx['matchId']])
            ->assertForbidden();
    }
}
