<?php

namespace App\Services\Admin;

use App\Models\InfrastructureCost;
use App\Models\InfrastructureCostSnapshot;
use App\Services\SiteSettings;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class InfrastructureCosts
{
    public function __construct(private SiteSettings $settings) {}

    public function activeLineItems()
    {
        return InfrastructureCost::query()
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    public function fixedMonthlyTotalUsd(): float
    {
        return (float) $this->activeLineItems()->sum(fn ($c) => $c->monthlyAmount());
    }

    public function aiSpendForMonth(CarbonImmutable $month): float
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();

        return (float) DB::table('ai_usage_events')
            ->whereBetween('created_at', [$start, $end])
            ->sum('estimated_cost_usd');
    }

    public function aiSpendRolling30Days(): float
    {
        return (float) DB::table('ai_usage_events')
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('estimated_cost_usd');
    }

    public function currentMonthTotalUsd(): float
    {
        return $this->fixedMonthlyTotalUsd() + $this->aiSpendForMonth(CarbonImmutable::now());
    }

    public function thresholdUsd(): float
    {
        return (float) $this->settings->get('cost_alert_threshold_usd', '100.00');
    }

    /**
     * Idempotently record a snapshot row for the given month. Re-running with the
     * same month replaces the existing snapshot (useful for backfilling).
     */
    public function recordSnapshotForMonth(CarbonImmutable $month): InfrastructureCostSnapshot
    {
        $firstOfMonth = $month->startOfMonth()->toDateString();
        $fixed = $this->fixedMonthlyTotalUsd();
        $ai = $this->aiSpendForMonth($month);
        $items = $this->activeLineItems()
            ->map(fn ($c) => [
                'name' => $c->name,
                'category' => $c->category,
                'amount_usd' => (float) $c->amount_usd,
                'billing_cycle' => $c->billing_cycle,
                'monthly_amount' => $c->monthlyAmount(),
            ])
            ->all();

        return InfrastructureCostSnapshot::updateOrCreate(
            ['snapshot_month' => $firstOfMonth],
            [
                'fixed_costs_usd' => $fixed,
                'ai_costs_usd' => $ai,
                'total_usd' => $fixed + $ai,
                'line_items_snapshot' => $items,
            ],
        );
    }

    /** @return array{labels: array<string>, fixed: array<float>, ai: array<float>, total: array<float>} */
    public function historicalChart(int $months = 12): array
    {
        $rows = InfrastructureCostSnapshot::query()
            ->orderByDesc('snapshot_month')
            ->limit($months)
            ->get()
            ->sortBy('snapshot_month')
            ->values();

        $labels = [];
        $fixed = [];
        $ai = [];
        $total = [];

        foreach ($rows as $row) {
            /** @var Carbon $month */
            $month = $row->snapshot_month;
            $labels[] = $month->format('M Y');
            $fixed[] = (float) $row->fixed_costs_usd;
            $ai[] = (float) $row->ai_costs_usd;
            $total[] = (float) $row->total_usd;
        }

        return compact('labels', 'fixed', 'ai', 'total');
    }

    /**
     * @return array{fired: bool, current_total: float, threshold: float, reason?: string}
     */
    public function maybeFireAlert(): array
    {
        $threshold = $this->thresholdUsd();
        $total = $this->currentMonthTotalUsd();
        $monthKey = CarbonImmutable::now()->format('Y-m');
        $lastNotified = (string) $this->settings->get('cost_alert_last_notified_month', '');

        if ($threshold <= 0) {
            return ['fired' => false, 'current_total' => $total, 'threshold' => $threshold, 'reason' => 'threshold disabled'];
        }
        if ($total < $threshold) {
            return ['fired' => false, 'current_total' => $total, 'threshold' => $threshold, 'reason' => 'under threshold'];
        }
        if ($lastNotified === $monthKey) {
            return ['fired' => false, 'current_total' => $total, 'threshold' => $threshold, 'reason' => 'already alerted this month'];
        }

        $this->settings->set('cost_alert_last_notified_month', $monthKey);

        return ['fired' => true, 'current_total' => $total, 'threshold' => $threshold];
    }
}
