<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Tightens UNIQUE (source_type, identifier) to reject a second
        // null-identifier row per source_type. Postgres treats null
        // as distinct in the base unique constraint, so without this
        // partial index, admin CRUD could silently allow two rows
        // like (remoteok, null) to coexist.
        DB::statement('CREATE UNIQUE INDEX job_sources_source_type_null_identifier_unique ON job_sources (source_type) WHERE identifier IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS job_sources_source_type_null_identifier_unique');
    }
};
