<?php

namespace Tests\Feature\Ai;

use App\Models\AiUsageEvent;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\CostCeilingExceeded;
use App\Services\Ai\Exceptions\ProviderRateLimited;
use App\Services\Ai\Exceptions\RerankFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiClientRerankTest extends TestCase
{
    use RefreshDatabase;

    protected function claudeRerankResponse(array $entries): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode(['matches' => $entries]),
            ]],
            'usage' => ['input_tokens' => 800, 'output_tokens' => 300],
        ];
    }

    public function test_returns_one_entry_per_input_job(): void
    {
        $inputJobs = [
            ['id' => 1, 'title' => 'Staff Engineer', 'company' => 'Acme', 'description' => 'Build things'],
            ['id' => 2, 'title' => 'Principal Engineer', 'company' => 'Beta', 'description' => 'Lead things'],
        ];

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->claudeRerankResponse([
                ['job_id' => 1, 'fit_score' => 82, 'rationale' => 'Strong skill match.', 'gaps' => [['requirement' => 'Go', 'severity' => 'stretch']], 'narrative' => 'Natural next step in your arc.'],
                ['job_id' => 2, 'fit_score' => 65, 'rationale' => 'Partial match.', 'gaps' => [], 'narrative' => 'A stretch role.'],
            ])),
        ]);

        $user = User::factory()->create();
        $results = app(AiClient::class)->forUser($user)->rerank('Resume text.', $inputJobs);

        $this->assertCount(2, $results);
        $this->assertSame(1, $results[0]['job_id']);
        $this->assertSame(82, $results[0]['fit_score']);
        $this->assertSame('stretch', $results[0]['gaps'][0]['severity']);
    }

    public function test_throws_rerank_failed_on_unparseable_response(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'not json']],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 10],
            ]),
        ]);

        $this->expectException(RerankFailed::class);
        app(AiClient::class)->forUser(User::factory()->create())->rerank('resume', [
            ['id' => 1, 'title' => 'X', 'company' => 'Y', 'description' => 'Z'],
        ]);
    }

    public function test_throws_rerank_failed_when_job_is_missing_from_response(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->claudeRerankResponse([
                ['job_id' => 1, 'fit_score' => 80, 'rationale' => 'ok', 'gaps' => [], 'narrative' => 'ok'],
                // job_id 2 missing
            ])),
        ]);

        $this->expectException(RerankFailed::class);
        app(AiClient::class)->forUser(User::factory()->create())->rerank('resume', [
            ['id' => 1, 'title' => 'A', 'company' => 'Y', 'description' => 'Z'],
            ['id' => 2, 'title' => 'B', 'company' => 'Y', 'description' => 'Z'],
        ]);
    }

    public function test_batches_large_input_into_max_batch_size_calls(): void
    {
        config(['services.ai.rerank_max_batch_size' => 2]);

        Http::fakeSequence('api.anthropic.com/*')
            ->pushResponse(Http::response($this->claudeRerankResponse([
                ['job_id' => 1, 'fit_score' => 50, 'rationale' => '', 'gaps' => [], 'narrative' => ''],
                ['job_id' => 2, 'fit_score' => 60, 'rationale' => '', 'gaps' => [], 'narrative' => ''],
            ])))
            ->pushResponse(Http::response($this->claudeRerankResponse([
                ['job_id' => 3, 'fit_score' => 70, 'rationale' => '', 'gaps' => [], 'narrative' => ''],
            ])));

        $results = app(AiClient::class)->forUser(User::factory()->create())->rerank('resume', [
            ['id' => 1, 'title' => 'A', 'company' => 'X', 'description' => '.'],
            ['id' => 2, 'title' => 'B', 'company' => 'X', 'description' => '.'],
            ['id' => 3, 'title' => 'C', 'company' => 'X', 'description' => '.'],
        ]);

        $this->assertCount(3, $results);
        $this->assertSame([1, 2, 3], array_column($results, 'job_id'));
    }

    public function test_cost_ceiling_exceeded_propagates(): void
    {
        $user = User::factory()->create();
        AiUsageEvent::create([
            'user_id' => $user->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
            'operation' => 'parse',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'estimated_cost_usd' => 5.01,
            'context_type' => 'resume',
            'context_id' => null,
            'created_at' => now(),
        ]);

        $this->expectException(CostCeilingExceeded::class);
        app(AiClient::class)->forUser($user)->rerank('resume', [
            ['id' => 1, 'title' => 'A', 'company' => 'X', 'description' => '.'],
        ]);
    }

    public function test_context_id_is_populated_with_search_profile_id(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->claudeRerankResponse([
                ['job_id' => 1, 'fit_score' => 80, 'rationale' => 'R', 'gaps' => [], 'narrative' => 'N'],
            ])),
        ]);

        $user = User::factory()->create();
        app(AiClient::class)->forUser($user)->rerank('resume', [
            ['id' => 1, 'title' => 'X', 'company' => 'Y', 'description' => 'Z'],
        ], searchProfileId: 42);

        $this->assertDatabaseHas('ai_usage_events', [
            'user_id' => $user->id,
            'operation' => 'rerank',
            'context_type' => 'rerank_batch',
            'context_id' => '42',
        ]);
    }

    public function test_rate_limit_translates(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                ['error' => ['message' => 'rate limited']],
                429,
                ['retry-after' => '7'],
            ),
        ]);

        $this->expectException(ProviderRateLimited::class);
        app(AiClient::class)->forUser(null)->rerank('resume', [
            ['id' => 1, 'title' => 'A', 'company' => 'X', 'description' => '.'],
        ]);
    }
}
