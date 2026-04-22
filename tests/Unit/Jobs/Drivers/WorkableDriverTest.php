<?php

namespace Tests\Unit\Jobs\Drivers;

use App\Models\JobSource;
use App\Services\Jobs\Drivers\WorkableDriver;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkableDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_yields_canonical_jobs(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/workable/shopify-happy.json'));
        Http::fake(['apply.workable.com/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'workable', 'identifier' => 'shopify']);
        $jobs = iterator_to_array(app(WorkableDriver::class)->fetch($source));

        $this->assertCount(1, $jobs);
        $this->assertSame('ABC-123-XYZ', $jobs[0]->sourceId);
        $this->assertSame('Senior Platform Engineer', $jobs[0]->title);
        $this->assertSame('shopify', $jobs[0]->sourceBoard);
        $this->assertSame('onsite', $jobs[0]->remoteMode);
        $this->assertSame('Toronto, Ontario, Canada', $jobs[0]->locationRaw);
    }

    public function test_telecommuting_true_produces_remote_only(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/workable/shopify-missing-location.json'));
        Http::fake(['apply.workable.com/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'workable', 'identifier' => 'shopify']);
        $jobs = iterator_to_array(app(WorkableDriver::class)->fetch($source));

        $this->assertSame('remote_only', $jobs[0]->remoteMode);
    }

    public function test_validate_identifier_requires_nonempty(): void
    {
        $driver = app(WorkableDriver::class);
        $this->assertFalse($driver->validateIdentifier(null));
        $this->assertTrue($driver->validateIdentifier('shopify'));
    }

    public function test_http_failure_translates(): void
    {
        Http::fake(['apply.workable.com/*' => Http::response('Not found', 404)]);

        $source = JobSource::factory()->create(['source_type' => 'workable', 'identifier' => 'missing']);
        $this->expectException(DriverFetchFailed::class);
        iterator_to_array(app(WorkableDriver::class)->fetch($source));
    }
}
