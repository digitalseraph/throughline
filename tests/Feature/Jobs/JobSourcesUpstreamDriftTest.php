<?php

namespace Tests\Feature\Jobs;

use App\DTO\CanonicalJobData;
use App\Models\JobSource;
use App\Services\Jobs\DriverRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobSourcesUpstreamDriftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('THROUGHLINE_UPSTREAM_DRIFT_TEST') !== '1') {
            $this->markTestSkipped('Upstream drift test gated by THROUGHLINE_UPSTREAM_DRIFT_TEST=1');
        }
    }

    public function test_greenhouse_stripe_still_returns_canonical_jobs(): void
    {
        $source = JobSource::factory()->create([
            'source_type' => 'greenhouse',
            'identifier' => 'stripe',
        ]);

        $driver = app(DriverRegistry::class)->driverFor('greenhouse');
        $jobs = iterator_to_array($driver->fetch($source));

        $this->assertGreaterThan(0, count($jobs), 'Stripe has zero open roles — likely a driver-level breakage');
        $first = $jobs[0];
        $this->assertInstanceOf(CanonicalJobData::class, $first);
        $this->assertNotEmpty($first->sourceId);
        $this->assertNotEmpty($first->title);
        $this->assertNotEmpty($first->applyUrl);
    }
}
