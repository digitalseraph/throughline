<?php

namespace Tests\Unit\Jobs\Drivers;

use App\Models\JobSource;
use App\Services\Jobs\Drivers\AshbyDriver;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AshbyDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_yields_canonical_jobs_with_comp(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/ashby/vercel-happy.json'));
        Http::fake(['api.ashbyhq.com/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'ashby', 'identifier' => 'vercel']);
        $jobs = iterator_to_array(app(AshbyDriver::class)->fetch($source));

        $this->assertCount(1, $jobs);
        $this->assertSame('job_01H1234567890', $jobs[0]->sourceId);
        $this->assertSame('Software Engineer, Edge', $jobs[0]->title);
        $this->assertSame(180000, $jobs[0]->compMin);
        $this->assertSame(220000, $jobs[0]->compMax);
        $this->assertSame('USD', $jobs[0]->compCurrency);
        $this->assertSame('onsite', $jobs[0]->remoteMode);
    }

    public function test_is_remote_true_produces_remote_only(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/ashby/vercel-remote.json'));
        Http::fake(['api.ashbyhq.com/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'ashby', 'identifier' => 'vercel']);
        $jobs = iterator_to_array(app(AshbyDriver::class)->fetch($source));

        $this->assertSame('remote_only', $jobs[0]->remoteMode);
        $this->assertNull($jobs[0]->compMin);
    }

    public function test_validate_identifier_requires_nonempty(): void
    {
        $driver = app(AshbyDriver::class);
        $this->assertFalse($driver->validateIdentifier(null));
        $this->assertTrue($driver->validateIdentifier('vercel'));
    }

    public function test_http_failure_translates(): void
    {
        Http::fake(['api.ashbyhq.com/*' => Http::response('Not found', 404)]);

        $source = JobSource::factory()->create(['source_type' => 'ashby', 'identifier' => 'missing']);
        $this->expectException(DriverFetchFailed::class);
        iterator_to_array(app(AshbyDriver::class)->fetch($source));
    }
}
