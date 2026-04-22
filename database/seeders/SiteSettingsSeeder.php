<?php

namespace Database\Seeders;

use App\Models\SiteSetting;
use Illuminate\Database\Seeder;

class SiteSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['key' => 'site_name', 'value' => 'Throughline', 'type' => 'string', 'description' => 'Public product name.'],
            ['key' => 'site_tagline', 'value' => 'Every career has a thread. Apply to jobs that follow yours.', 'type' => 'string', 'description' => 'Tagline shown on marketing surfaces.'],
            ['key' => 'signups_enabled', 'value' => true, 'type' => 'bool', 'description' => 'Whether new-user registration is accepted.'],
            ['key' => 'maintenance_mode', 'value' => false, 'type' => 'bool', 'description' => 'Show a maintenance banner / block non-admin access.'],
            ['key' => 'default_source_cadence_minutes', 'value' => 60, 'type' => 'int', 'description' => 'Default cadence for new job-source rows.'],
            ['key' => 'matching_top_k', 'value' => 30, 'type' => 'int', 'description' => 'How many stage-1 matches to keep per profile.'],
            ['key' => 'rerank_shortlist_size', 'value' => 20, 'type' => 'int', 'description' => 'Batch size for stage-2 LLM rerank.'],
            ['key' => 'rerank_enabled', 'value' => true, 'type' => 'bool', 'description' => 'Master switch for stage-2 rerank.'],
            ['key' => 'cost_ceiling_per_user_per_day_usd', 'value' => '5.00', 'type' => 'string', 'description' => 'Per-user daily AI spend ceiling (USD).'],
            ['key' => 'brand_asset_overrides', 'value' => [], 'type' => 'json', 'description' => 'Optional per-install brand asset paths.'],
            ['key' => 'stage1_score_floor', 'value' => '0.2', 'type' => 'string', 'description' => 'Minimum cosine similarity for a job to enter the feed.'],
            ['key' => 'openai_embeddings_model', 'value' => 'text-embedding-3-small', 'type' => 'string', 'description' => 'OpenAI model used for both resume and job embeddings.'],
            ['key' => 'anthropic_parse_model', 'value' => 'claude-sonnet-4-6', 'type' => 'string', 'description' => 'Anthropic model used by AiClient::parseResume.'],
        ];

        foreach ($defaults as $row) {
            SiteSetting::firstOrCreate(['key' => $row['key']], $row);
        }
    }
}
