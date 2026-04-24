<?php

namespace Database\Seeders;

use App\Models\InfrastructureCost;
use Illuminate\Database\Seeder;

class InfrastructureCostsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['name' => 'Laravel Forge',           'category' => 'Hosting',   'amount_usd' => 12.00, 'notes' => 'Server management subscription.'],
            ['name' => 'DigitalOcean Droplet',    'category' => 'Hosting',   'amount_usd' => 18.00, 'notes' => 'App server, 2 vCPU / 2 GB.'],
            ['name' => 'DO Managed Postgres',     'category' => 'Database',  'amount_usd' => 15.00, 'notes' => 'Postgres 16 + pgvector, Dev DB tier.'],
            ['name' => 'DO Managed Redis',        'category' => 'Database',  'amount_usd' => 15.00, 'notes' => 'Cache + queue + session.'],
            ['name' => 'DO Spaces',               'category' => 'Storage',   'amount_usd' => 5.00,  'notes' => 'Resume + document uploads.'],
            ['name' => 'Cloudflare',              'category' => 'CDN',       'amount_usd' => 0.00,  'notes' => 'Free tier — DNS + edge + DDoS.'],
            ['name' => 'Postmark',                'category' => 'Email',     'amount_usd' => 15.00, 'notes' => 'Transactional email, 10k/mo tier.'],
            ['name' => 'Sentry',                  'category' => 'Monitoring','amount_usd' => 0.00,  'notes' => 'Free tier — 5k events / 10k transactions.'],
        ];

        foreach ($defaults as $row) {
            InfrastructureCost::firstOrCreate(
                ['name' => $row['name']],
                $row + ['billing_cycle' => 'monthly', 'is_active' => true],
            );
        }
    }
}
