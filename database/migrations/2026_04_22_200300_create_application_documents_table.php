<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24)->default('other');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime', 64)->nullable();
            $table->integer('size_bytes')->default(0);
            $table->timestamp('uploaded_at')->useCurrent();

            $table->index('application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_documents');
    }
};
