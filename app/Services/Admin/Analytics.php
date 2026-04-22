<?php

namespace App\Services\Admin;

use App\Models\Application;
use App\Models\JobSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class Analytics
{
    public function dailyIngestion(int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $rows = DB::table('canonical_jobs')
            ->selectRaw('DATE(created_at) as day, source, count(*) as total')
            ->where('created_at', '>=', $from)
            ->groupBy('day', 'source')
            ->orderBy('day')
            ->get();

        return $this->toTimeSeries($from, $days, $rows, 'day', 'source', 'total');
    }

    public function dailyMatches(int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $rows = DB::table('job_matches')
            ->selectRaw('DATE(created_at) as day, count(*) as total')
            ->where('created_at', '>=', $from)
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return $this->toSingleSeries($from, $days, $rows, 'day', 'total', 'Matches');
    }

    public function dailyRerankCalls(int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $rows = DB::table('ai_usage_events')
            ->selectRaw('DATE(created_at) as day, count(*) as total')
            ->where('operation', 'rerank')
            ->where('created_at', '>=', $from)
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return $this->toSingleSeries($from, $days, $rows, 'day', 'total', 'Rerank calls');
    }

    public function dailyAiCost(int $days = 30, ?int $userId = null): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $query = DB::table('ai_usage_events')
            ->selectRaw('DATE(created_at) as day, sum(estimated_cost_usd) as total')
            ->where('created_at', '>=', $from);

        if ($userId) {
            $query->where('user_id', $userId);
        }

        $rows = $query->groupBy('day')->orderBy('day')->get();

        return $this->toSingleSeries($from, $days, $rows, 'day', 'total', 'AI spend (USD)', fn ($v) => (float) $v);
    }

    public function applicationFunnel(): array
    {
        return Application::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();
    }

    public function sourceHealth(): array
    {
        return JobSource::query()
            ->select('id', 'source_type', 'identifier', 'display_name', 'is_enabled', 'last_fetched_at', 'last_error', 'consecutive_failures')
            ->orderBy('source_type')
            ->get()
            ->toArray();
    }

    protected function toTimeSeries(Carbon $from, int $days, $rows, string $dayCol, string $groupCol, string $valueCol): array
    {
        $labels = [];
        for ($i = 0; $i < $days; $i++) {
            $labels[] = $from->copy()->addDays($i)->toDateString();
        }

        $groups = [];
        foreach ($rows as $row) {
            $groups[$row->{$groupCol}] = $groups[$row->{$groupCol}] ?? array_fill(0, $days, 0);
            $dayIdx = array_search((string) $row->{$dayCol}, $labels, true);
            if ($dayIdx !== false) {
                $groups[$row->{$groupCol}][$dayIdx] = (int) $row->{$valueCol};
            }
        }

        $datasets = [];
        foreach ($groups as $group => $data) {
            $datasets[] = ['label' => $group, 'data' => $data];
        }

        return ['labels' => $labels, 'datasets' => $datasets];
    }

    protected function toSingleSeries(Carbon $from, int $days, $rows, string $dayCol, string $valueCol, string $label, ?callable $cast = null): array
    {
        $labels = [];
        $data = array_fill(0, $days, 0);
        for ($i = 0; $i < $days; $i++) {
            $labels[] = $from->copy()->addDays($i)->toDateString();
        }
        foreach ($rows as $row) {
            $dayIdx = array_search((string) $row->{$dayCol}, $labels, true);
            if ($dayIdx !== false) {
                $data[$dayIdx] = $cast ? $cast($row->{$valueCol}) : (int) $row->{$valueCol};
            }
        }

        return ['labels' => $labels, 'datasets' => [['label' => $label, 'data' => $data]]];
    }
}
