<?php

namespace Tests\Feature\Admin;

use App\Jobs\FetchJobSource;
use App\Models\JobSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class JobSourceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->actingAs($user)->get(route('admin.job-sources.index'))->assertForbidden();
    }

    public function test_admin_can_create_greenhouse_source(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.job-sources.store'), [
            'source_type' => 'greenhouse',
            'identifier' => 'acme',
            'display_name' => 'Acme (Greenhouse)',
            'fetch_cadence_minutes' => 30,
            'is_enabled' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('job_sources', [
            'source_type' => 'greenhouse',
            'identifier' => 'acme',
        ]);
    }

    public function test_greenhouse_source_requires_identifier(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.job-sources.store'), [
            'source_type' => 'greenhouse',
            'identifier' => '',
            'display_name' => 'Bad',
            'fetch_cadence_minutes' => 30,
        ])->assertSessionHasErrors('identifier');
    }

    public function test_remoteok_source_accepts_null_identifier(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.job-sources.store'), [
            'source_type' => 'remoteok',
            'identifier' => null,
            'display_name' => 'RemoteOK',
            'fetch_cadence_minutes' => 120,
        ])->assertRedirect();

        $this->assertDatabaseHas('job_sources', ['source_type' => 'remoteok', 'identifier' => null]);
    }

    public function test_fetch_now_dispatches_job(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $source = JobSource::factory()->create(['source_type' => 'greenhouse', 'identifier' => 'stripe']);

        $this->actingAs($admin)->post(route('admin.job-sources.fetch-now', $source))->assertRedirect();

        Queue::assertPushed(FetchJobSource::class, fn ($j) => $j->jobSourceId === $source->id);
    }
}
