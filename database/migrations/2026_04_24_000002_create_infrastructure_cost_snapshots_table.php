<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infrastructure_cost_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_month')->unique();
            $table->decimal('fixed_costs_usd', 10, 2);
            $table->decimal('ai_costs_usd', 10, 2);
            $table->decimal('total_usd', 10, 2);
            $table->jsonb('line_items_snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infrastructure_cost_snapshots');
    }
};
