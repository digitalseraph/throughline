<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_sources', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 32);
            $table->string('identifier', 120)->nullable();
            $table->string('display_name', 160);
            $table->boolean('is_enabled')->default(true);
            $table->integer('fetch_cadence_minutes')->default(60);
            $table->timestamp('last_fetched_at')->nullable();
            $table->text('last_error')->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->timestamps();

            $table->unique(['source_type', 'identifier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_sources');
    }
};
