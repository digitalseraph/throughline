<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('source_file_path')->nullable();
            $table->string('source_mime', 64)->nullable();
            $table->text('parsed_text')->nullable();
            $table->jsonb('structured_json')->nullable();
            $table->string('parse_status', 24)->default('pending');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'parse_status']);
        });

        DB::statement('ALTER TABLE resumes ADD COLUMN embedding vector(1536)');
        DB::statement('CREATE UNIQUE INDEX resumes_user_id_default_unique ON resumes (user_id) WHERE is_default = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('resumes');
    }
};
