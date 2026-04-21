<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EmbedJob;
use App\Jobs\FetchJobSource;
use App\Models\CanonicalJob;
use App\Models\JobSource;
use App\Services\Jobs\DriverRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FetchJobSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_upserts_canonical_jobs_and_dispatches_embed(): void
    {
        Queue::fake();
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/greenhouse/stripe-happy.json'));
        Http::fake(['boards-api.greenhouse.io/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'greenhouse', 'identifier' => 'stripe']);

        (new FetchJobSource($source->id))->handle(app(DriverRegistry::class));

        $this->assertGreaterThan(0, CanonicalJob::count());
        $source->refresh();
        $this->assertNotNull($source->last_fetched_at);
        $this->assertNull($source->last_error);
        Queue::assertPushed(EmbedJob::class);
    }

    public function test_upstream_failure_updates_last_error_and_tracks_consecutive(): void
    {
        Http::fake(['boards-api.greenhouse.io/*' => Http::response('Gone', 410)]);
        $source = JobSource::factory()->create([
            'source_type' => 'greenhouse',
            'identifier' => 'dead',
            'consecutive_failures' => 2,
        ]);

        (new FetchJobSource($source->id))->handle(app(DriverRegistry::class));

        $source->refresh();
        $this->assertNotNull($source->last_error);
        $this->assertSame(3, $source->consecutive_failures);
        $this->assertFalse($source->is_enabled, 'auto-pause after 3 consecutive failures');
    }

    public function test_existing_jobs_get_last_seen_refreshed_not_duplicated(): void
    {
        Queue::fake();
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/greenhouse/stripe-happy.json'));
        Http::fake(['boards-api.greenhouse.io/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'greenhouse', 'identifier' => 'stripe']);

        (new FetchJobSource($source->id))->handle(app(DriverRegistry::class));
        $initial = CanonicalJob::count();

        (new FetchJobSource($source->id))->handle(app(DriverRegistry::class));

        $this->assertSame($initial, CanonicalJob::count(), 'idempotent upsert on (source, source_id)');
    }
}
