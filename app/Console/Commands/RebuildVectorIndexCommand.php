<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildVectorIndexCommand extends Command
{
    protected $signature = 'throughline:rebuild-vector-index {table : resumes|canonical_jobs} {--lists= : override the auto-computed lists count}';
    protected $description = 'Drop and recreate the ivfflat index on a vector column with a tuned lists parameter.';

    public function handle(): int
    {
        $table = $this->argument('table');
        if (!in_array($table, ['resumes', 'canonical_jobs'], true)) {
            $this->error('Table must be one of: resumes, canonical_jobs');
            return self::FAILURE;
        }

        $indexName = "{$table}_embedding_ivfflat";
        $rowCount = (int) DB::table($table)->whereNotNull('embedding')->count();
        $lists = $this->option('lists') ? (int) $this->option('lists') : max(1, (int) ceil(sqrt($rowCount)));

        $this->info("Rebuilding {$indexName}: {$rowCount} rows → lists = {$lists}");
        $start = microtime(true);

        DB::statement("DROP INDEX IF EXISTS {$indexName}");
        DB::statement("CREATE INDEX {$indexName} ON {$table} USING ivfflat (embedding vector_cosine_ops) WITH (lists = {$lists})");

        $this->info(sprintf('Done in %.2fs.', microtime(true) - $start));
        return self::SUCCESS;
    }
}
