<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EmbedJob;
use App\Jobs\FanOutStage1Matches;
use App\Models\CanonicalJob;
use App\Services\Ai\AiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class EmbedJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_vector_and_dispatches_fanout(): void
    {
        Queue::fake();

        $job = CanonicalJob::factory()->create();
        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->with(null)->andReturnSelf();
        $ai->shouldReceive('embed')->with(Mockery::any(), 'job')->andReturn(array_fill(0, 1536, 0.3));
        $this->app->instance(AiClient::class, $ai);

        (new EmbedJob($job->id))->handle($ai);

        $vector = \DB::table('canonical_jobs')->where('id', $job->id)->value('embedding');
        $this->assertNotNull($vector);
        Queue::assertPushed(FanOutStage1Matches::class, fn ($j) => $j->canonicalJobId === $job->id);
    }
}
