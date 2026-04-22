<?php

namespace Tests\Feature\Events;

use App\Events\ResumeReady;
use App\Jobs\EmbedResume;
use App\Models\Resume;
use App\Models\User;
use App\Services\Ai\AiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class ResumeReadyBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_embed_resume_fires_resume_ready_event(): void
    {
        Event::fake([ResumeReady::class]);

        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->parsed()->create();

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.5));
        $this->app->instance(AiClient::class, $ai);

        (new EmbedResume($resume->id))->handle($ai);

        Event::assertDispatched(ResumeReady::class, fn ($e) => $e->resume->id === $resume->id);
    }
}
