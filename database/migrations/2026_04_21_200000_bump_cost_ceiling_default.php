<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only bump if the row still carries Plan 1's default. Admin-edited
        // values are preserved. Value column is json; the Plan 1 default was
        // stored as the JSON-encoded string "2.0".
        DB::table('site_settings')
            ->where('key', 'cost_ceiling_per_user_per_day_usd')
            ->whereRaw("value::text = '\"2.0\"'")
            ->update(['value' => DB::raw("'\"5.00\"'::json")]);
    }

    public function down(): void
    {
        DB::table('site_settings')
            ->where('key', 'cost_ceiling_per_user_per_day_usd')
            ->whereRaw("value::text = '\"5.00\"'")
            ->update(['value' => DB::raw("'\"2.0\"'::json")]);
    }
};
