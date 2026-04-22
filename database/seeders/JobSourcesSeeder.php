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
        ];

        foreach ($seeds as $seed) {
            JobSource::updateOrCreate(
                ['source_type' => $seed['source_type'], 'identifier' => $seed['identifier']],
                $seed + ['is_enabled' => true, 'fetch_cadence_minutes' => 60],
            );
        }
    }
}
