<?php

namespace App\Http\Controllers\Admin;

use App\Models\Application;
use App\Models\CanonicalJob;
use App\Models\JobMatch;
use App\Models\JobSource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends BaseController
{
    public function show(): Response
    {
        $todayStart = now()->startOfDay();
        $monthStart = now()->startOfMonth();
        $sevenDaysAgo = now()->subDays(7);

        $cards = [
            'total_users' => User::count(),
            'active_7d' => User::where('last_login_at', '>=', $sevenDaysAgo)->count(),
            'total_jobs' => CanonicalJob::count(),
            'jobs_today' => CanonicalJob::where('created_at', '>=', $todayStart)->count(),
            'total_matches' => JobMatch::count(),
            'applications_by_status' => Application::query()
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->toArray(),
            'ai_spend_today' => (float) DB::table('ai_usage_events')->where('created_at', '>=', $todayStart)->sum('estimated_cost_usd'),
            'ai_spend_month' => (float) DB::table('ai_usage_events')->where('created_at', '>=', $monthStart)->sum('estimated_cost_usd'),
            'sources' => JobSource::query()
                ->select('id', 'source_type', 'identifier', 'display_name', 'is_enabled', 'last_fetched_at', 'last_error', 'consecutive_failures')
                ->orderBy('source_type')
                ->get(),
        ];

        return Inertia::render('admin/Overview', ['cards' => $cards]);
    }
}
