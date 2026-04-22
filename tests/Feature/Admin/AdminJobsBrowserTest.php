<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\CanonicalJob;
use App\Models\Resume;
use App\Models\SearchProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminJobsBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_paginates_and_filters_by_source(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CanonicalJob::factory()->count(3)->create(['source' => 'greenhouse']);
        CanonicalJob::factory()->count(2)->create(['source' => 'lever']);

        $response = $this->actingAs($admin)->get(route('admin.jobs.index', ['source' => 'greenhouse']));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertCount(3, $props['jobs']['data']);
    }

    public function test_show_includes_matches_and_applications(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->parsed()->create();
        $profile = SearchProfile::factory()->for($user)->create(['resume_id' => $resume->id]);
        $job = CanonicalJob::factory()->create();

        \DB::table('job_matches')->insert([
            'user_id' => $user->id,
            'search_profile_id' => $profile->id,
            'canonical_job_id' => $job->id,
            'stage1_score' => 0.8,
            'created_at' => now(),
        ]);
        Application::factory()->for($user)->create(['canonical_job_id' => $job->id]);

        $response = $this->actingAs($admin)->get(route('admin.jobs.show', $job));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertCount(1, $props['matches']);
        $this->assertCount(1, $props['applications']);
    }
}
