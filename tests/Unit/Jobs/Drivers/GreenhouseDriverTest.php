<?php

namespace Tests\Unit\Jobs\Drivers;

use App\Models\JobSource;
use App\Services\Jobs\Drivers\GreenhouseDriver;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GreenhouseDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_yields_canonical_jobs_from_happy_fixture(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/greenhouse/stripe-happy.json'));
        Http::fake(['boards-api.greenhouse.io/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'greenhouse', 'identifier' => 'stripe']);
        $jobs = iterator_to_array(app(GreenhouseDriver::class)->fetch($source));

        $this->assertGreaterThan(0, count($jobs));
        $this->assertSame('stripe', $jobs[0]->sourceBoard);
        $this->assertNotEmpty($jobs[0]->sourceId);
        $this->assertNotEmpty($jobs[0]->title);
        $this->assertNotEmpty($jobs[0]->applyUrl);
    }

    public function test_handles_missing_comp_metadata(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/greenhouse/stripe-missing-comp.json'));
        Http::fake(['boards-api.greenhouse.io/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'greenhouse', 'identifier' => 'stripe']);
        $jobs = iterator_to_array(app(GreenhouseDriver::class)->fetch($source));

        $this->assertNull($jobs[0]->compMin);
        $this->assertNull($jobs[0]->compMax);
    }

    public function test_failed_http_throws_driver_fetch_failed(): void
    {
        Http::fake(['boards-api.greenhouse.io/*' => Http::response('Not found', 404)]);

        $source = JobSource::factory()->create(['source_type' => 'greenhouse', 'identifier' => 'missing']);

        $this->expectException(DriverFetchFailed::class);
        iterator_to_array(app(GreenhouseDriver::class)->fetch($source));
    }

    public function test_validate_identifier_requires_nonempty_slug(): void
    {
        $driver = app(GreenhouseDriver::class);
        $this->assertFalse($driver->validateIdentifier(null));
        $this->assertFalse($driver->validateIdentifier(''));
        $this->assertTrue($driver->validateIdentifier('stripe'));
    }
}
