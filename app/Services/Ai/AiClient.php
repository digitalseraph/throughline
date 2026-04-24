<?php

namespace App\Services\Ai;

use App\Models\AiUsageEvent;
use App\Models\User;
use App\Services\Ai\Exceptions\CostCeilingExceeded;
use App\Services\Ai\Exceptions\ParseFailed;
use App\Services\Ai\Exceptions\ProviderRateLimited;
use App\Services\Ai\Exceptions\RerankFailed;
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

        $system = 'You extract structured data from resumes. Return ONLY compact JSON matching: {"text": "<plain-text summary>", "structured": {"name": "...", "titles": [...], "companies": [...], "skills": [...], "years_experience": <int|null>}}';

        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.api_key'),
            'anthropic-version' => '2023-06-01',
        ])->acceptJson()->asJson()->post('https://api.anthropic.com/v1/messages', [
            'model' => $model,
            'max_tokens' => 2048,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $rawText]],
        ]);

        if ($response->status() === 429) {
            throw new ProviderRateLimited('anthropic', (int) ($response->header('retry-after') ?: 30));
        }

        $response->throw();

        $data = $response->json();
        $raw = $data['content'][0]['text'] ?? '';
        $parsed = json_decode($this->stripJsonFences($raw), true);
        if (! is_array($parsed) || ! isset($parsed['text'], $parsed['structured'])) {
            throw new ParseFailed('Claude response did not contain text + structured keys', $raw);
        }

        $inputTokens = (int) ($data['usage']['input_tokens'] ?? 0);
        $outputTokens = (int) ($data['usage']['output_tokens'] ?? 0);

        $this->recordUsage('anthropic', $model, 'parse', $inputTokens, $outputTokens, 'resume');

        return $parsed;
    }

    public function rerank(string $resumeText, array $jobs, ?int $searchProfileId = null): array
    {
        if (empty($jobs)) {
            return [];
        }

        $maxBatch = (int) ($this->settings->get('rerank_max_batch_size') ?? config('services.ai.rerank_max_batch_size', 20));
        $batches = array_chunk($jobs, $maxBatch);

        $allResults = [];
        foreach ($batches as $batch) {
            $batchResults = $this->rerankBatch($resumeText, $batch, $searchProfileId);
            $allResults = array_merge($allResults, $batchResults);
        }

        return $allResults;
    }

    protected function rerankBatch(string $resumeText, array $jobs, ?int $searchProfileId = null): array
    {
        $this->enforceCostCeiling();
        $this->consumeRateBucket('anthropic');

        $model = $this->settings->get('anthropic_rerank_model', 'claude-sonnet-4-6');

        $system = 'You rerank job postings against a candidate resume. Return ONLY compact JSON matching {"matches": [{"job_id": <int>, "fit_score": <int 0-100>, "rationale": "<2-4 sentences>", "gaps": [{"requirement": "<string>", "severity": "blocker"|"stretch"|"minor"}], "narrative": "<one-sentence framing of how this fits the candidate arc>"}]}. Include exactly one entry per input job. Never omit a job.';

        $userContent = "Candidate resume:\n---\n{$resumeText}\n---\n\nJob postings to rerank:\n".
            json_encode(array_map(
                fn ($j) => [
                    'id' => (int) ($j['id'] ?? 0),
                    'title' => (string) ($j['title'] ?? ''),
                    'company' => (string) ($j['company'] ?? ''),
                    'description' => mb_substr((string) ($j['description'] ?? ''), 0, 2000),
                ],
                $jobs,
            ));

        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.api_key'),
            'anthropic-version' => '2023-06-01',
        ])->acceptJson()->asJson()->post('https://api.anthropic.com/v1/messages', [
            'model' => $model,
            'max_tokens' => 4096,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $userContent]],
        ]);

        if ($response->status() === 429) {
            throw new ProviderRateLimited('anthropic', (int) ($response->header('retry-after') ?: 30));
        }

        $response->throw();

        $data = $response->json();
        $raw = $data['content'][0]['text'] ?? '';
        $parsed = json_decode($this->stripJsonFences($raw), true);

        if (! is_array($parsed) || ! isset($parsed['matches']) || ! is_array($parsed['matches'])) {
            throw new RerankFailed('response did not contain matches array', $raw);
        }

        $byId = [];
        foreach ($parsed['matches'] as $match) {
            if (! isset($match['job_id'], $match['fit_score'], $match['rationale'], $match['gaps'], $match['narrative'])) {
                throw new RerankFailed('match entry missing required fields', $raw);
            }
            $byId[(int) $match['job_id']] = $match;
        }

        $ordered = [];
        foreach ($jobs as $job) {
            if (! isset($byId[$job['id']])) {
                throw new RerankFailed("missing response for job_id {$job['id']}", $raw);
            }
            $ordered[] = $byId[$job['id']];
        }

        $inputTokens = (int) ($data['usage']['input_tokens'] ?? 0);
        $outputTokens = (int) ($data['usage']['output_tokens'] ?? 0);
        $this->recordUsage('anthropic', $model, 'rerank', $inputTokens, $outputTokens, 'rerank_batch', $searchProfileId !== null ? (string) $searchProfileId : null);

        return $ordered;
    }

    /**
     * Enforces the per-user daily cost ceiling. The check-then-insert
     * sequence is non-atomic by design: concurrent calls can both pass
     * the guard and together overshoot by one call per concurrent pair.
     * This is acceptable per design spec §4 ("soft-enforced"), since the
     * overshoot is bounded to a few cents and the alternative (per-user
     * Redis lock or DB-level advisory lock) costs more than it saves.
     */
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

        // Atomic increment avoids the read-then-decrement race that two
        // concurrent requests could exploit to both pass a guard check.
        $used = Cache::increment($key);

        if ($used === 1) {
            // First call in the window — set expiry so the counter resets.
            Cache::put($key, 1, now()->addSeconds($config['per_seconds']));
        }

        if ($used > $config['max']) {
            throw new ProviderRateLimited($provider, $config['per_seconds']);
        }
    }

    protected function recordUsage(
        string $provider,
        string $model,
        string $operation,
        int $inputTokens,
        int $outputTokens,
        ?string $contextType,
        ?string $contextId = null,
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
            'context_id' => $contextId,
            'created_at' => now(),
        ]);
    }

    /**
     * Claude commonly wraps JSON in ```json ... ``` markdown fences even when
     * told not to. Strip those before json_decode so a perfectly-shaped
     * response isn't rejected for purely cosmetic reasons.
     */
    protected function stripJsonFences(string $text): string
    {
        $text = trim($text);

        if (preg_match('/^```(?:json)?\s*\n?(.+?)\n?\s*```$/s', $text, $matches)) {
            return trim($matches[1]);
        }

        return $text;
    }
}
