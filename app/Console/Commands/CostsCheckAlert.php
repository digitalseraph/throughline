<?php

namespace App\Console\Commands;

use App\Mail\CostAlertMail;
use App\Models\User;
use App\Services\Admin\InfrastructureCosts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class CostsCheckAlert extends Command
{
    protected $signature = 'costs:check-alert';

    protected $description = 'Send an alert email to admins if the current-month cost has crossed the configured threshold.';

    public function handle(InfrastructureCosts $costs): int
    {
        $result = $costs->maybeFireAlert();

        if (! $result['fired']) {
            $this->info("No alert: {$result['reason']} (total \${$result['current_total']}, threshold \${$result['threshold']}).");

            return self::SUCCESS;
        }

        $fixed = $costs->fixedMonthlyTotalUsd();
        $ai = $result['current_total'] - $fixed;
        $admins = User::where('role', 'admin')
            ->whereNull('deactivated_at')
            ->where('notification_email_enabled', true)
            ->get();

        foreach ($admins as $admin) {
            try {
                Mail::to($admin)->queue(new CostAlertMail(
                    admin: $admin,
                    currentTotalUsd: $result['current_total'],
                    thresholdUsd: $result['threshold'],
                    fixedUsd: $fixed,
                    aiUsd: $ai,
                ));
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->info("Alert dispatched to {$admins->count()} admin(s): \${$result['current_total']} exceeds \${$result['threshold']}.");

        return self::SUCCESS;
    }
}
