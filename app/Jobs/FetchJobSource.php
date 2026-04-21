<?php

namespace App\Jobs;

use App\Models\CanonicalJob;
use App\Models\JobSource;
use App\Services\Jobs\DriverRegistry;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchJobSource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $jobSourceId) {}

    public function handle(DriverRegistry $registry): void
    {
        $source = JobSource::findOrFail($this->jobSourceId);
        if (! $source->is_enabled) {
            return;
        }

        $driver = $registry->driverFor($source->source_type);

        try {
            foreach ($driver->fetch($source) as $canonical) {
                $existing = CanonicalJob::where('source', $source->source_type)
                    ->where('source_id', $canonical->sourceId)
                    ->first();
                $descriptionBefore = $existing?->description;

                $job = CanonicalJob::updateOrCreate(
                    ['source' => $source->source_type, 'source_id' => $canonical->sourceId],
                    $canonical->toEloquentAttributes($source->source_type),
                );

                if ($job->wasRecentlyCreated || $descriptionBefore !== $canonical->description) {
                    EmbedJob::dispatch($job->id);
                }
            }
            $source->update(['last_fetched_at' => now(), 'last_error' => null, 'consecutive_failures' => 0]);
        } catch (DriverFetchFailed $e) {
            $failures = $source->consecutive_failures + 1;
            $source->update([
                'last_error' => $e->getMessage(),
                'consecutive_failures' => $failures,
                'is_enabled' => $failures < 3,
            ]);
        }
    }
}
