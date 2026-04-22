<?php

namespace Tests\Feature\Admin;

use App\Jobs\EmbedJob;
use App\Jobs\ParseResume;
use App\Models\AdminActivityLog;
use App\Models\CanonicalJob;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminErrorLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_failed_parses_and_stuck_embeddings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        Resume::factory()->for($user)->create(['parse_status' => 'parse_failed']);

        $response = $this->actingAs($admin)->get(route('admin.errors'));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertCount(1, $props['failedParses']);
    }

    public function test_retry_resume_parse_dispatches_and_audits(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create(['parse_status' => 'parse_failed']);

        $this->actingAs($admin)
            ->post(route('admin.errors.resume.retry-parse', $resume))
            ->assertRedirect();

        Queue::assertPushed(ParseResume::class);
        $this->assertSame('pending', $resume->fresh()->parse_status);
        $this->assertSame(1, AdminActivityLog::where('action', 'resume.parse_retried')->count());
    }

    public function test_retry_job_embed_dispatches(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $job = CanonicalJob::factory()->create();

        $this->actingAs($admin)->post(route('admin.errors.job.retry-embed', $job))->assertRedirect();

        Queue::assertPushed(EmbedJob::class);
        $this->assertSame(1, AdminActivityLog::where('action', 'job.embed_retried')->count());
    }
}
