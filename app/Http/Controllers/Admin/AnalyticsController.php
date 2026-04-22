<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\Analytics;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends BaseController
{
    public function show(Analytics $analytics): Response
    {
        return Inertia::render('admin/Analytics', [
            'ingestion' => $analytics->dailyIngestion(30),
            'matches' => $analytics->dailyMatches(30),
            'rerankCalls' => $analytics->dailyRerankCalls(30),
            'aiCost' => $analytics->dailyAiCost(30),
            'funnel' => $analytics->applicationFunnel(),
        ]);
    }
}
