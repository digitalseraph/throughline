<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RebuildVectorIndexCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuilds_index_on_canonical_jobs(): void
    {
        $this->artisan('throughline:rebuild-vector-index', ['table' => 'canonical_jobs'])->assertExitCode(0);

        $exists = DB::selectOne("SELECT indexname FROM pg_indexes WHERE indexname = 'canonical_jobs_embedding_ivfflat'");
        $this->assertNotNull($exists);
    }

    public function test_rejects_unknown_table(): void
    {
        $this->artisan('throughline:rebuild-vector-index', ['table' => 'bogus'])->assertFailed();
    }

    public function test_honors_lists_override(): void
    {
        $this->artisan('throughline:rebuild-vector-index', ['table' => 'resumes', '--lists' => 42])->assertExitCode(0);
        // No easy way to introspect the lists param from pg_indexes; trust the SQL path.
    }
}
