<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\ProviderRateLimited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiClientRateLimiterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_translates_provider_429_into_typed_exception(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(
                ['error' => ['message' => 'Rate limit reached']],
                429,
                ['retry-after' => '12'],
            ),
        ]);

        $this->expectException(ProviderRateLimited::class);

        try {
            app(AiClient::class)->forUser(null)->embed('x', 'job');
        } catch (ProviderRateLimited $e) {
            $this->assertSame('openai', $e->provider);
            $this->assertSame(12, $e->retryAfterSeconds);
            throw $e;
        }
    }

    public function test_blocks_when_local_token_bucket_empty(): void
    {
        Cache::put('ai_ratelimit:openai', 0, now()->addSeconds(30));

        $this->expectException(ProviderRateLimited::class);
        app(AiClient::class)->forUser(null)->embed('x', 'job');
    }
}
