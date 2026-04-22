<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('canonical_job_id')->constrained('canonical_jobs')->cascadeOnDelete();
            $table->foreignId('search_profile_id')->nullable()->constrained('search_profiles')->nullOnDelete();
            $table->foreignId('resume_id')->nullable()->constrained('resumes')->nullOnDelete();
            $table->string('status', 24)->default('saved');
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('next_action_at')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('stage2_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'canonical_job_id']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'next_action_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
