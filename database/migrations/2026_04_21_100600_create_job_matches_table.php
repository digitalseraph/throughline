<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('search_profile_id')->constrained('search_profiles')->cascadeOnDelete();
            $table->foreignId('canonical_job_id')->constrained('canonical_jobs')->cascadeOnDelete();
            $table->float('stage1_score');
            $table->integer('stage2_score')->nullable();
            $table->text('stage2_rationale')->nullable();
            $table->jsonb('stage2_gaps')->nullable();
            $table->text('stage2_narrative')->nullable();
            $table->timestamp('stage2_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['search_profile_id', 'canonical_job_id']);
            $table->index(['user_id', 'dismissed_at', 'stage1_score']);
            $table->index(['search_profile_id', 'stage1_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_matches');
    }
};
