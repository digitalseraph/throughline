<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resume_id')->nullable()->constrained('resumes')->nullOnDelete();
            $table->string('name', 120);
            $table->jsonb('role_keywords')->default('[]');
            $table->jsonb('locations')->default('[]');
            $table->string('remote_mode', 16)->default('any');
            $table->integer('comp_min')->nullable();
            $table->string('comp_currency', 8)->nullable();
            $table->jsonb('industries')->default('[]');
            $table->jsonb('seniority_levels')->default('[]');
            $table->jsonb('excluded_companies')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_profiles');
    }
};
