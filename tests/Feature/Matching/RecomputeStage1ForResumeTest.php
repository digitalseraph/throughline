<?php

namespace Tests\Feature\Matching;

use App\Jobs\RecomputeStage1ForResume;
use App\Models\CanonicalJob;
use App\Models\JobMatch;
use App\Models\Resume;
use App\Models\SearchProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecomputeStage1ForResumeTest extends TestCase
{
    use RefreshDatabase;

    public function test_inserts_matches_for_all_profiles_using_the_resume(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->parsed()->create();

        $profile1 = SearchProfile::factory()->for($user)->create(['resume_id' => $resume->id, 'remote_mode' => 'any']);
        $profile2 = SearchProfile::factory()->for($user)->create(['resume_id' => $resume->id, 'remote_mode' => 'any']);

        $job1 = CanonicalJob::factory()->create();
        $job2 = CanonicalJob::factory()->create();

        $this->writeVector('resumes', $resume->id, array_fill(0, 1536, 0.5));
        $this->writeVector('canonical_jobs', $job1->id, array_fill(0, 1536, 0.5));
        $this->writeVector('canonical_jobs', $job2->id, array_fill(0, 1536, 0.5));

        (new RecomputeStage1ForResume($resume->id))->handle();

        $this->assertSame(4, JobMatch::count(), 'one match per (profile, job) pair');
        $this->assertGreaterThan(0.9, JobMatch::first()->stage1_score, 'identical vectors → similarity ~ 1.0');
    }

    public function test_deletes_existing_matches_before_reinsert(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->parsed()->create();
        $profile = SearchProfile::factory()->for($user)->create(['resume_id' => $resume->id, 'remote_mode' => 'any']);
        $job = CanonicalJob::factory()->create();

        $this->writeVector('resumes', $resume->id, array_fill(0, 1536, 0.5));
        $this->writeVector('canonical_jobs', $job->id, array_fill(0, 1536, 0.5));

        // Pre-existing match with a different score
        JobMatch::insert([
            'user_id' => $user->id,
            'search_profile_id' => $profile->id,
            'canonical_job_id' => $job->id,
            'stage1_score' => 0.1,
            'created_at' => now(),
        ]);

        (new RecomputeStage1ForResume($resume->id))->handle();

        $this->assertSame(1, JobMatch::count());
        $this->assertGreaterThan(0.9, JobMatch::first()->stage1_score, 'match was recomputed, not kept');
    }

    public function test_skips_resume_with_no_embedding(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->parsed()->create();
        SearchProfile::factory()->for($user)->create(['resume_id' => $resume->id]);

        // Resume has no embedding written
        (new RecomputeStage1ForResume($resume->id))->handle();

        $this->assertSame(0, JobMatch::count());
    }

    protected function writeVector(string $table, int $id, array $vec): void
    {
        DB::statement("UPDATE {$table} SET embedding = ?::vector WHERE id = ?", [
            '['.implode(',', $vec).']',
            $id,
        ]);
    }
}
