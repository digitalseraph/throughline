<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Services\Ai\AiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiClientEmbedTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_vector_and_writes_usage_row(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'data' => [['embedding' => array_fill(0, 1536, 0.5)]],
                'usage' => ['prompt_tokens' => 42, 'total_tokens' => 42],
            ]),
        ]);

        $user = User::factory()->create();
        $vector = app(AiClient::class)->forUser($user)->embed('resume text', 'resume');

        $this->assertCount(1536, $vector);
        $this->assertDatabaseHas('ai_usage_events', [
            'user_id' => $user->id,
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'operation' => 'embed',
            'input_tokens' => 42,
            'context_type' => 'resume',
        ]);
    }

    public function test_null_user_writes_system_row(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'data' => [['embedding' => array_fill(0, 1536, 0.1)]],
                'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
            ]),
        ]);

        app(AiClient::class)->forUser(null)->embed('job desc', 'job');

        $this->assertDatabaseHas('ai_usage_events', [
            'user_id' => null,
            'context_type' => 'job',
        ]);
    }
}
