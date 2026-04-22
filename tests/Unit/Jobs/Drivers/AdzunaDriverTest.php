<?php

namespace Tests\Unit\Jobs\Drivers;

use App\Models\JobSource;
use App\Services\Jobs\Drivers\AdzunaDriver;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdzunaDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.adzuna.app_id' => 'test-id', 'services.adzuna.app_key' => 'test-key']);
    }

    public function test_yields_canonical_jobs_from_page1(): void
    {
        $page1 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/adzuna/us-page1.json')), true);
        $page2 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/adzuna/us-page2-empty.json')), true);

        Http::fake([
            'api.adzuna.com/*/search/1*' => Http::response($page1),
            'api.adzuna.com/*/search/2*' => Http::response($page2),
        ]);

        $source = JobSource::factory()->create([
            'source_type' => 'adzuna',
            'identifier' => 'us',
            'config_json' => ['country' => 'us'],
        ]);
        $jobs = iterator_to_array(app(AdzunaDriver::class)->fetch($source));

        $this->assertCount(2, $jobs);
        $this->assertSame('4847392017', $jobs[0]->sourceId);
        $this->assertSame('OpenAI', $jobs[0]->companyName);
        $this->assertSame(180000, $jobs[0]->compMin);
        $this->assertSame(280000, $jobs[0]->compMax);
    }

    public function test_stops_paginating_when_results_empty(): void
    {
        $page1 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/adzuna/us-page1.json')), true);
        $page2Empty = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/adzuna/us-page2-empty.json')), true);

        $callCount = 0;
        Http::fake(function ($request) use ($page1, $page2Empty, &$callCount) {
            $callCount++;
            if (str_contains($request->url(), '/search/1')) {
                return Http::response($page1);
            }

            return Http::response($page2Empty);
        });

        $source = JobSource::factory()->create([
            'source_type' => 'adzuna',
            'identifier' => 'us',
            'config_json' => ['country' => 'us'],
        ]);
        iterator_to_array(app(AdzunaDriver::class)->fetch($source));

        // Page 1 + page 2 (empty) — then stop. Should be exactly 2 calls.
        $this->assertSame(2, $callCount);
    }

    public function test_null_salary_preserved(): void
    {
        $page1 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/adzuna/us-page1.json')), true);
        $page2Empty = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/adzuna/us-page2-empty.json')), true);

        Http::fake([
            'api.adzuna.com/*/search/1*' => Http::response($page1),
            'api.adzuna.com/*/search/2*' => Http::response($page2Empty),
        ]);

        $source = JobSource::factory()->create([
            'source_type' => 'adzuna',
            'identifier' => 'us',
            'config_json' => ['country' => 'us'],
        ]);
        $jobs = iterator_to_array(app(AdzunaDriver::class)->fetch($source));

        $this->assertNull($jobs[1]->compMin);
        $this->assertNull($jobs[1]->compMax);
    }

    public function test_missing_credentials_throws_driver_fetch_failed(): void
    {
        config(['services.adzuna.app_id' => null, 'services.adzuna.app_key' => null]);

        $source = JobSource::factory()->create([
            'source_type' => 'adzuna',
            'identifier' => 'us',
            'config_json' => ['country' => 'us'],
        ]);

        $this->expectException(DriverFetchFailed::class);
        iterator_to_array(app(AdzunaDriver::class)->fetch($source));
    }

    public function test_currency_reflects_country_code(): void
    {
        $page1 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/adzuna/us-page1.json')), true);
        $page2Empty = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/adzuna/us-page2-empty.json')), true);

        Http::fake([
            'api.adzuna.com/*/search/1*' => Http::response($page1),
            'api.adzuna.com/*/search/2*' => Http::response($page2Empty),
        ]);

        $source = JobSource::factory()->create([
            'source_type' => 'adzuna',
            'identifier' => 'gb',
            'config_json' => ['country' => 'gb'],
        ]);
        $jobs = iterator_to_array(app(AdzunaDriver::class)->fetch($source));

        $this->assertSame('GBP', $jobs[0]->compCurrency);
    }

    public function test_http_failure_translates(): void
    {
        Http::fake(['api.adzuna.com/*' => Http::response('rate limit', 429)]);

        $source = JobSource::factory()->create([
            'source_type' => 'adzuna',
            'identifier' => 'us',
            'config_json' => ['country' => 'us'],
        ]);

        $this->expectException(DriverFetchFailed::class);
        iterator_to_array(app(AdzunaDriver::class)->fetch($source));
    }
}
