<?php

namespace Tests\Feature\Resumes;

use App\Jobs\EmbedResume;
use App\Jobs\ParseResume;
use App\Jobs\RecomputeStage1ForResume;
use App\Models\Resume;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\ParseFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ResumePipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_parse_resume_success_path_dispatches_embed(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create([
            'source_file_path' => 'stub.txt',
            'source_mime' => 'text/plain',
        ]);
        \Storage::disk('local')->put('stub.txt', 'raw text');

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->with(Mockery::type(User::class))->andReturnSelf();
        $ai->shouldReceive('parseResume')->andReturn([
            'text' => 'Parsed text.',
            'structured' => ['skills' => ['PHP']],
        ]);
        $this->app->instance(AiClient::class, $ai);

        (new ParseResume($resume->id))->handle($ai);

        $resume->refresh();
        $this->assertSame('parsed', $resume->parse_status);
        $this->assertSame('Parsed text.', $resume->parsed_text);
        Queue::assertPushed(EmbedResume::class, fn ($job) => $job->resumeId === $resume->id);
    }

    public function test_parse_failure_sets_parse_failed_and_preserves_raw(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create([
            'source_file_path' => 'stub.txt',
            'source_mime' => 'text/plain',
        ]);
        \Storage::disk('local')->put('stub.txt', 'unparseable');

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('parseResume')->andThrow(
            new ParseFailed('bad json', 'raw')
        );
        $this->app->instance(AiClient::class, $ai);

        (new ParseResume($resume->id))->handle($ai);

        $resume->refresh();
        $this->assertSame('parse_failed', $resume->parse_status);
        $this->assertSame('unparseable', $resume->parsed_text);
    }

    public function test_embed_resume_writes_vector_and_dispatches_recompute(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->parsed()->create();

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('embed')->with(Mockery::any(), 'resume')->andReturn(array_fill(0, 1536, 0.25));
        $this->app->instance(AiClient::class, $ai);

        (new EmbedResume($resume->id))->handle($ai);

        $vector = \DB::table('resumes')->where('id', $resume->id)->value('embedding');
        $this->assertNotNull($vector);
        Queue::assertPushed(RecomputeStage1ForResume::class);
    }
}
