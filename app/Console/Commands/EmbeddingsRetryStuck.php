<?php

namespace App\Console\Commands;

use App\Jobs\EmbedJob;
use App\Jobs\EmbedResume;
use App\Models\CanonicalJob;
use App\Models\Resume;
use Illuminate\Console\Command;

class EmbeddingsRetryStuck extends Command
{
    protected $signature = 'embeddings:retry-stuck {--limit=200 : Cap per kind to avoid flooding the queue}';

    protected $description = 'Re-dispatch EmbedJob / EmbedResume for rows whose embedding never landed (orphaned by a queue or API outage).';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $jobIds = CanonicalJob::query()
            ->whereNull('embedding')
            ->whereNull('closed_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($jobIds as $id) {
            EmbedJob::dispatch($id);
        }

        $resumeIds = Resume::query()
            ->where('parse_status', 'parsed')
            ->whereNull('embedding')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($resumeIds as $id) {
            EmbedResume::dispatch($id);
        }

        $this->info("Re-dispatched {$jobIds->count()} job embeddings + {$resumeIds->count()} resume embeddings.");

        return self::SUCCESS;
    }
}
