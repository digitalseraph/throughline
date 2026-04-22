<?php

namespace Tests\Unit\Jobs\Drivers;

use App\Models\JobSource;
use App\Services\Jobs\Drivers\USAJobsDriver;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class USAJobsDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.usajobs.auth_key' => 'test-key',
            'services.usajobs.user_agent_email' => 'test@throughline.test',
        ]);
    }

    public function test_yields_canonical_jobs(): void
    {
        $page1 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/usajobs/page1.json')), true);
        $page2 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/usajobs/page2-empty.json')), true);

        Http::fake([
            'data.usajobs.gov/api/search*Page=1*' => Http::response($page1),
            'data.usajobs.gov/api/search*Page=2*' => Http::response($page2),
        ]);

        $source = JobSource::factory()->create(['source_type' => 'usajobs', 'identifier' => null]);
        $jobs = iterator_to_array(app(USAJobsDriver::class)->fetch($source));

        $this->assertCount(2, $jobs);
        $this->assertSame('834219200', $jobs[0]->sourceId);
        $this->assertSame('U.S. Cyber Command', $jobs[0]->companyName);
        $this->assertSame('Cybersecurity Specialist', $jobs[0]->title);
        $this->assertSame(112015, $jobs[0]->compMin);
        $this->assertSame(145617, $jobs[0]->compMax);
        $this->assertSame('USD', $jobs[0]->compCurrency);
    }

    public function test_remote_location_produces_remote_only(): void
    {
        $page1 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/usajobs/page1.json')), true);
        $page2 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/usajobs/page2-empty.json')), true);

        Http::fake([
            'data.usajobs.gov/api/search*Page=1*' => Http::response($page1),
            'data.usajobs.gov/api/search*Page=2*' => Http::response($page2),
        ]);

        $source = JobSource::factory()->create(['source_type' => 'usajobs', 'identifier' => null]);
        $jobs = iterator_to_array(app(USAJobsDriver::class)->fetch($source));

        $this->assertSame('remote_only', $jobs[1]->remoteMode);
    }

    public function test_stops_paginating_on_empty_results(): void
    {
        $page1 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/usajobs/page1.json')), true);
        $page2 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/usajobs/page2-empty.json')), true);

        $callCount = 0;
        Http::fake(function ($request) use ($page1, $page2, &$callCount) {
            $callCount++;
            if (str_contains($request->url(), 'Page=1')) {
                return Http::response($page1);
            }
            return Http::response($page2);
        });

        $source = JobSource::factory()->create(['source_type' => 'usajobs', 'identifier' => null]);
        iterator_to_array(app(USAJobsDriver::class)->fetch($source));

        $this->assertSame(2, $callCount);
    }

    public function test_missing_credentials_throws_driver_fetch_failed(): void
    {
        config(['services.usajobs.auth_key' => null]);

        $source = JobSource::factory()->create(['source_type' => 'usajobs', 'identifier' => null]);
        $this->expectException(DriverFetchFailed::class);
        iterator_to_array(app(USAJobsDriver::class)->fetch($source));
    }

    public function test_http_failure_translates(): void
    {
        Http::fake(['data.usajobs.gov/*' => Http::response('', 503)]);

        $source = JobSource::factory()->create(['source_type' => 'usajobs', 'identifier' => null]);
        $this->expectException(DriverFetchFailed::class);
        iterator_to_array(app(USAJobsDriver::class)->fetch($source));
    }
}
