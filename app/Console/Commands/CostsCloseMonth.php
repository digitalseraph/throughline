<?php

namespace App\Console\Commands;

use App\Services\Admin\InfrastructureCosts;
use App\Services\SiteSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CostsCloseMonth extends Command
{
    protected $signature = 'costs:close-month {--month= : YYYY-MM to close (defaults to last month)}';

    protected $description = 'Write the infrastructure_cost_snapshots row for the given month and reset the alert flag.';

    public function handle(InfrastructureCosts $costs, SiteSettings $settings): int
    {
        $monthOpt = (string) $this->option('month');
        $month = $monthOpt !== ''
            ? CarbonImmutable::createFromFormat('Y-m', $monthOpt)->startOfMonth()
            : CarbonImmutable::now()->subMonth()->startOfMonth();

        $snapshot = $costs->recordSnapshotForMonth($month);
        $this->info("Snapshot recorded for {$month->format('Y-m')}: total \${$snapshot->total_usd} (fixed \${$snapshot->fixed_costs_usd}, AI \${$snapshot->ai_costs_usd}).");

        // Reset the alert flag at month rollover so the new month starts fresh.
        if ($month->addMonth()->format('Y-m') === CarbonImmutable::now()->format('Y-m')) {
            $settings->set('cost_alert_last_notified_month', '');
            $this->info('Alert dedupe flag reset for the new month.');
        }

        return self::SUCCESS;
    }
}
