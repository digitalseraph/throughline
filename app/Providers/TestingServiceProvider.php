<?php

namespace App\Providers;

use App\Services\Ai\AiClient;
use Illuminate\Support\ServiceProvider;

class TestingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Only bind the fake when serving for Playwright E2E (APP_ENV=testing but NOT
        // running PHPUnit). PHPUnit tests mock the HTTP layer directly via Http::fake().
        if (! app()->environment('testing') || app()->runningUnitTests()) {
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
            };
        });
    }
}
