<?php

namespace Database\Seeders;

use App\Models\JobSource;
use Illuminate\Database\Seeder;

class JobSourcesSeeder extends Seeder
{
    public function run(): void
    {
        $seeds = [
            // Greenhouse boards (public, well-known engineering companies with open job feeds).
            ['source_type' => 'greenhouse', 'identifier' => 'stripe',      'display_name' => 'Stripe (Greenhouse)'],
            ['source_type' => 'greenhouse', 'identifier' => 'airbnb',      'display_name' => 'Airbnb (Greenhouse)'],
            ['source_type' => 'greenhouse', 'identifier' => 'notion',      'display_name' => 'Notion (Greenhouse)'],
            ['source_type' => 'greenhouse', 'identifier' => 'figma',       'display_name' => 'Figma (Greenhouse)'],
            ['source_type' => 'greenhouse', 'identifier' => 'linear',      'display_name' => 'Linear (Greenhouse)'],

            // Lever companies.
            ['source_type' => 'lever',      'identifier' => 'netflix',     'display_name' => 'Netflix (Lever)'],
            ['source_type' => 'lever',      'identifier' => 'shopify',     'display_name' => 'Shopify (Lever)'],
            ['source_type' => 'lever',      'identifier' => 'palantir',    'display_name' => 'Palantir (Lever)'],

            // RemoteOK is a single aggregator.
            ['source_type' => 'remoteok',   'identifier' => null,          'display_name' => 'RemoteOK (all tags)'],

            // Workable, Ashby, and Adzuna (Plan 3 drivers).
            ['source_type' => 'workable',   'identifier' => 'shopify',     'display_name' => 'Shopify (Workable)',  'config_json' => null],
            ['source_type' => 'ashby',      'identifier' => 'vercel',      'display_name' => 'Vercel (Ashby)',      'config_json' => null],
            ['source_type' => 'adzuna',     'identifier' => 'us',          'display_name' => 'Adzuna US',           'config_json' => ['country' => 'us']],
            ['source_type' => 'remotive',  'identifier' => null, 'display_name' => 'Remotive',              'config_json' => null],
            ['source_type' => 'usajobs',   'identifier' => null, 'display_name' => 'USAJobs (US Federal)',  'config_json' => null],
        ];

        foreach ($seeds as $seed) {
            JobSource::updateOrCreate(
                ['source_type' => $seed['source_type'], 'identifier' => $seed['identifier']],
                $seed + ['is_enabled' => true, 'fetch_cadence_minutes' => 60],
            );
        }
    }
}
