<?php

namespace App\Services\Ai;

use App\Models\AiUsageEvent;
use App\Models\User;
use App\Services\Ai\Exceptions\CostCeilingExceeded;
use App\Services\Ai\Exceptions\ParseFailed;
use App\Services\Ai\Exceptions\ProviderRateLimited;
use App\Services\SiteSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AiClient
{
    protected ?User $actingUser = null;

    public function __construct(protected SiteSettings $settings) {}

    public function forUser(?User $user): self
    {
        $clone = clone $this;
        $clone->actingUser = $user;

        return $clone;
    }

    public function embed(string $text, string $context): array
    {
        $this->enforceCostCeiling();
        $this->consumeRateBucket('openai');

        $model = $this->settings->get('openai_embeddings_model', 'text-embedding-3-small');

        $response = Http::withToken(config('services.openai.api_key'))
            ->acceptJson()
            ->asJson()
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => $model,
                'input' => $text,
            ]);

        if ($response->status() === 429) {
            throw new ProviderRateLimited('openai', (int) ($response->header('retry-after') ?: 30));
        }

        $response->throw();

        $data = $response->json();
        $vector = $data['data'][0]['embedding'] ?? [];
        $inputTokens = (int) ($data['usage']['prompt_tokens'] ?? 0);

        $this->recordUsage('openai', $model, 'embed', $inputTokens, 0, $context);

        return $vector;
    }

    public function parseResume(string $rawText): array
    {
        $this->enforceCostCeiling();
        $this->consumeRateBucket('anthropic');

        $model = $this->settings->get('anthropic_parse_model', 'claude-sonnet-4-6');

        $prompt = <<<PROMPT
You extract structured data from resumes. Return ONLY compact JSON matching:
{"text": "<plain-text summary>", "structured": {"name": "...", "titles": [...], "companies": [...], "skills": [...], "years_experience": <int|null>}}

Resume follows:
---
{$rawText}
PROMPT;

        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.api_key'),
            'anthropic-version' => '2023-06-01',
        ])->acceptJson()->asJson()->post('https://api.anthropic.com/v1/messages', [
            'model' => $model,
            'max_tokens' => 2048,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);

        if ($response->status() === 429) {
            throw new ProviderRateLimited('anthropic', (int) ($response->header('retry-after') ?: 30));
        }

        $response->throw();

        $data = $response->json();
        $raw = $data['content'][0]['text'] ?? '';
        $parsed = json_decode($raw, true);
        if (! is_array($parsed) || ! isset($parsed['text'], $parsed['structured'])) {
            throw new ParseFailed('Claude response did not contain text + structured keys', $raw);
        }

        $inputTokens = (int) ($data['usage']['input_tokens'] ?? 0);
        $outputTokens = (int) ($data['usage']['output_tokens'] ?? 0);

        $this->recordUsage('anthropic', $model, 'parse', $inputTokens, $outputTokens, 'resume');

        return $parsed;
    }

    protected function enforceCostCeiling(): void
    {
        if (! $this->actingUser) {
            return;
        }

        $ceiling = (float) $this->settings->get('cost_ceiling_per_user_per_day_usd', '5.00');
        $spent = (float) AiUsageEvent::where('user_id', $this->actingUser->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('estimated_cost_usd');

        if ($spent >= $ceiling) {
            throw new CostCeilingExceeded($this->actingUser->id, $spent, $ceiling);
        }
    }

    protected function consumeRateBucket(string $provider): void
    {
        $config = config("services.ai.rate_limit.{$provider}");
        $key = "ai_ratelimit:{$provider}";
        $current = Cache::get($key);

        if ($current === null) {
            Cache::put($key, $config['max'] - 1, now()->addSeconds($config['per_seconds']));

            return;
        }

        if ($current <= 0) {
            throw new ProviderRateLimited($provider, $config['per_seconds']);
        }

        Cache::decrement($key);
    }

    protected function recordUsage(
        string $provider,
        string $model,
        string $operation,
        int $inputTokens,
        int $outputTokens,
        ?string $contextType,
    ): void {
        $rates = config("services.ai.cost_per_1k_tokens.{$model}", ['input' => 0, 'output' => 0]);
        $cost = ($inputTokens / 1000) * $rates['input'] + ($outputTokens / 1000) * $rates['output'];

        AiUsageEvent::create([
            'user_id' => $this->actingUser?->id,
            'provider' => $provider,
            'model' => $model,
            'operation' => $operation,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'estimated_cost_usd' => $cost,
            'context_type' => $contextType,
            'context_id' => null,
            'created_at' => now(),
        ]);
    }
}
