<?php

namespace Tests\Unit\Jobs\Drivers;

use App\Models\JobSource;
use App\Services\Jobs\Drivers\RemotiveDriver;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemotiveDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_happy_path_yields_jobs(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/remotive/feed-happy.json'));
        Http::fake(['remotive.com/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'remotive', 'identifier' => null]);
        $jobs = iterator_to_array(app(RemotiveDriver::class)->fetch($source));

        $this->assertCount(2, $jobs);
        $this->assertSame('112233', $jobs[0]->sourceId);
        $this->assertSame('Senior Backend Engineer', $jobs[0]->title);
        $this->assertSame('Acme', $jobs[0]->companyName);
        $this->assertSame('remote_only', $jobs[0]->remoteMode);
        $this->assertSame(120000, $jobs[0]->compMin);
        $this->assertSame(180000, $jobs[0]->compMax);
        $this->assertSame('USD', $jobs[0]->compCurrency);
    }

    public function test_empty_salary_preserved(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/remotive/feed-happy.json'));
        Http::fake(['remotive.com/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'remotive', 'identifier' => null]);
        $jobs = iterator_to_array(app(RemotiveDriver::class)->fetch($source));

        $this->assertSame('112234', $jobs[1]->sourceId);
        $this->assertNull($jobs[1]->compMin);
        $this->assertNull($jobs[1]->compMax);
        $this->assertNull($jobs[1]->compCurrency);
    }

    public function test_validate_identifier_accepts_null(): void
    {
        $driver = app(RemotiveDriver::class);
        $this->assertTrue($driver->validateIdentifier(null));
        $this->assertTrue($driver->validateIdentifier(''));
        $this->assertTrue($driver->validateIdentifier('anything'));
    }

    public function test_http_failure_translates(): void
    {
        Http::fake(['remotive.com/*' => Http::response('Service Unavailable', 503)]);

        $source = JobSource::factory()->create(['source_type' => 'remotive', 'identifier' => null]);
        $this->expectException(DriverFetchFailed::class);
        iterator_to_array(app(RemotiveDriver::class)->fetch($source));
    }
}
