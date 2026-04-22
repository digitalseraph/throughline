<?php

namespace App\Providers;

use App\Services\Ai\AiClient;
use Illuminate\Support\ServiceProvider;

class TestingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Only bind the fake when serving for Playwright E2E (APP_ENV=testing and
        // PLAYWRIGHT_E2E=true). PHPUnit tests mock the HTTP layer directly via Http::fake()
        // and must NOT get this fake, so we gate on the explicit flag rather than
        // runningUnitTests() which always returns true when APP_ENV=testing.
        if (! app()->environment('testing') || ! env('PLAYWRIGHT_E2E')) {
            return;
        }

        $this->app->singleton(AiClient::class, function () {
            return new class extends AiClient
            {
                public function __construct() {}

                public function embed(string $text, string $context): array
                {
                    return array_fill(0, 1536, 0.5);
                }

                public function parseResume(string $rawText): array
                {
                    return [
                        'text' => $rawText,
                        'structured' => ['skills' => ['PHP'], 'titles' => ['Engineer']],
                    ];
                }

                public function rerank(string $resumeText, array $jobs, ?int $searchProfileId = null): array
                {
                    return array_map(fn ($j) => [
                        'job_id' => $j['id'],
                        'fit_score' => 75,
                        'rationale' => 'Deterministic fake rationale for testing.',
                        'gaps' => [['requirement' => 'Example requirement', 'severity' => 'stretch']],
                        'narrative' => 'Fake career-arc narrative.',
                    ], $jobs);
                }
            };
        });
    }
}
