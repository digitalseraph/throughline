<?php

namespace Tests\Feature\Ai;

use App\Models\AiUsageEvent;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\CostCeilingExceeded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiClientCostCeilingTest extends TestCase
{
    use RefreshDatabase;

    public function test_throws_when_user_exceeds_daily_ceiling(): void
    {
        $user = User::factory()->create();

        AiUsageEvent::create([
            'user_id' => $user->id,
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'operation' => 'embed',
            'input_tokens' => 100_000_000,
            'output_tokens' => 0,
            'estimated_cost_usd' => 5.01,
            'context_type' => 'resume',
            'context_id' => '1',
            'created_at' => now(),
        ]);

        $this->expectException(CostCeilingExceeded::class);
        app(AiClient::class)->forUser($user)->embed('hello world', 'resume');
    }

    public function test_system_calls_bypass_ceiling(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'data' => [['embedding' => array_fill(0, 1536, 0.0)]],
                'usage' => ['prompt_tokens' => 3, 'total_tokens' => 3],
            ]),
        ]);

        $vector = app(AiClient::class)->forUser(null)->embed('system text', 'job');
        $this->assertCount(1536, $vector);
    }
}
