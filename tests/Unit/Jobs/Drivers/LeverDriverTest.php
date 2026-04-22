<?php

namespace Tests\Unit\Jobs\Drivers;

use App\Models\JobSource;
use App\Services\Jobs\Drivers\LeverDriver;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LeverDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_yields_canonical_jobs(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/lever/netflix-happy.json'));
        Http::fake(['api.lever.co/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'lever', 'identifier' => 'netflix']);
        $jobs = iterator_to_array(app(LeverDriver::class)->fetch($source));

        $this->assertGreaterThan(0, count($jobs));
        $this->assertSame(220000, $jobs[0]->compMin);
        $this->assertSame(380000, $jobs[0]->compMax);
        $this->assertSame('USD', $jobs[0]->compCurrency);
        $this->assertSame('onsite', $jobs[0]->remoteMode);
    }

    public function test_multilocation_parses_as_hybrid(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/lever/netflix-multilocation.json'));
        Http::fake(['api.lever.co/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'lever', 'identifier' => 'netflix']);
        $jobs = iterator_to_array(app(LeverDriver::class)->fetch($source));

        $this->assertSame('hybrid_ok', $jobs[0]->remoteMode);
        $this->assertCount(2, $jobs[0]->locations);
    }

    public function test_validate_identifier_requires_nonempty(): void
    {
        $driver = app(LeverDriver::class);
        $this->assertFalse($driver->validateIdentifier(null));
        $this->assertTrue($driver->validateIdentifier('netflix'));
    }

    public function test_http_failure_translates(): void
    {
        Http::fake(['api.lever.co/*' => Http::response('Not found', 404)]);

        $source = JobSource::factory()->create(['source_type' => 'lever', 'identifier' => 'missing']);
        $this->expectException(DriverFetchFailed::class);
        iterator_to_array(app(LeverDriver::class)->fetch($source));
    }
}
