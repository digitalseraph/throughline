<?php

namespace Tests\Feature\Matching;

use App\Jobs\FanOutStage1Matches;
use App\Models\CanonicalJob;
use App\Models\JobMatch;
use App\Models\Resume;
use App\Models\SearchProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FanOutStage1MatchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_match_when_profile_resume_and_job_have_embeddings(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->parsed()->create();
        $profile = SearchProfile::factory()->for($user)->create(['resume_id' => $resume->id, 'remote_mode' => 'any']);
        $job = CanonicalJob::factory()->create(['remote_mode' => 'remote_only']);

        $this->writeVector('resumes', $resume->id, array_fill(0, 1536, 0.5));
        $this->writeVector('canonical_jobs', $job->id, array_fill(0, 1536, 0.5));

        (new FanOutStage1Matches($job->id))->handle();

        $this->assertSame(1, JobMatch::count());
        $match = JobMatch::first();
        $this->assertSame($profile->id, $match->search_profile_id);
        $this->assertGreaterThan(0.9, $match->stage1_score, 'identical vectors produce similarity ~ 1.0');
    }

    public function test_skips_profile_with_no_resume(): void
    {
        $user = User::factory()->create();
        SearchProfile::factory()->for($user)->create(['resume_id' => null]);
        $job = CanonicalJob::factory()->create();
        $this->writeVector('canonical_jobs', $job->id, array_fill(0, 1536, 0.1));

        (new FanOutStage1Matches($job->id))->handle();

        $this->assertSame(0, JobMatch::count());
    }

    public function test_respects_hard_filter_and_score_floor(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->parsed()->create();
        $profile = SearchProfile::factory()->for($user)->create([
            'resume_id' => $resume->id,
            'remote_mode' => 'remote_only',
        ]);
        $onsite = CanonicalJob::factory()->create(['remote_mode' => 'onsite']);
        $remote = CanonicalJob::factory()->create(['remote_mode' => 'remote_only']);

        $this->writeVector('resumes', $resume->id, array_fill(0, 1536, 0.5));
        $this->writeVector('canonical_jobs', $onsite->id, array_fill(0, 1536, 0.5));
        $this->writeVector('canonical_jobs', $remote->id, array_fill(0, 1536, 0.5));

        (new FanOutStage1Matches($onsite->id))->handle();
        (new FanOutStage1Matches($remote->id))->handle();

        $this->assertSame(1, JobMatch::count());
        $this->assertSame($remote->id, JobMatch::first()->canonical_job_id);
    }

    protected function writeVector(string $table, int $id, array $vec): void
    {
        DB::statement("UPDATE {$table} SET embedding = ?::vector WHERE id = ?", [
            '['.implode(',', $vec).']',
            $id,
        ]);
    }
}
