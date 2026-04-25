<?php

namespace Tests\Feature\Console;

use App\Jobs\EmbedJob;
use App\Jobs\EmbedResume;
use App\Models\CanonicalJob;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class EmbeddingsRetryStuckTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_redispatches_canonical_jobs_with_no_embedding(): void
    {
        Bus::fake();
        CanonicalJob::factory()->count(3)->create();

        $this->artisan('embeddings:retry-stuck')->assertExitCode(0);

        Bus::assertDispatchedTimes(EmbedJob::class, 3);
    }

    public function test_command_skips_jobs_that_are_closed(): void
    {
        Bus::fake();
        CanonicalJob::factory()->create(['closed_at' => now()]);
        CanonicalJob::factory()->create(['closed_at' => null]);

        $this->artisan('embeddings:retry-stuck')->assertExitCode(0);

        Bus::assertDispatchedTimes(EmbedJob::class, 1);
    }

    public function test_command_redispatches_parsed_resumes_with_no_embedding(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        Resume::factory()->for($user)->create(['parse_status' => 'parsed']);
        Resume::factory()->for($user)->create(['parse_status' => 'parsing']);

        $this->artisan('embeddings:retry-stuck')->assertExitCode(0);

        Bus::assertDispatchedTimes(EmbedResume::class, 1);
    }

    public function test_command_respects_limit_option(): void
    {
        Bus::fake();
        CanonicalJob::factory()->count(5)->create();

        $this->artisan('embeddings:retry-stuck', ['--limit' => 2])->assertExitCode(0);

        Bus::assertDispatchedTimes(EmbedJob::class, 2);
    }
}
