<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Table name is 'canonical_jobs' to avoid colliding with Laravel's built-in 'jobs' queue table.
        Schema::create('canonical_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('source_id', 120);
            $table->string('source_board', 120)->nullable();
            $table->string('company_name', 160);
            $table->string('title', 240);
            $table->text('description');
            $table->string('location_raw', 240)->nullable();
            $table->jsonb('locations')->default('[]');
            $table->string('remote_mode', 16)->default('any');
            $table->integer('comp_min')->nullable();
            $table->integer('comp_max')->nullable();
            $table->string('comp_currency', 8)->nullable();
            $table->string('seniority', 40)->nullable();
            $table->string('employment_type', 40)->nullable();
            $table->string('apply_url', 500);
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->jsonb('raw_payload')->default('{}');
            $table->timestamps();

            $table->unique(['source', 'source_id']);
            $table->index('posted_at');
            $table->index(['source', 'closed_at']);
        });

        DB::statement('ALTER TABLE canonical_jobs ADD COLUMN embedding vector(1536)');
        DB::statement('CREATE INDEX canonical_jobs_embedding_ivfflat ON canonical_jobs USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('canonical_jobs');
    }
};
