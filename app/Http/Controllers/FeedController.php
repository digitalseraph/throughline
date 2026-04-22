<?php

namespace App\Http\Controllers;

use App\Jobs\RerankShortlist;
use App\Models\SearchProfile;
use App\Services\SiteSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class FeedController extends Controller
{
    public function show(Request $request, SearchProfile $profile): Response
    {
        abort_unless((int) $profile->user_id === $request->user()->id, 403);

        $raw = $request->boolean('raw');
        $settings = app(SiteSettings::class);
        $rerankEnabledRaw = $settings->get('rerank_enabled', 'true');
        $rerankEnabled = ! in_array(strtolower((string) $rerankEnabledRaw), ['false', '0', '']);
        $shortlistSize = (int) $settings->get('rerank_shortlist_size', '20');
        $ttlHours = (int) $settings->get('stage2_ttl_hours', '24');

        if (! $raw && $rerankEnabled) {
            $staleCount = DB::table('job_matches')
                ->where('search_profile_id', $profile->id)
                ->whereNull('dismissed_at')
                ->where(fn ($q) => $q->whereNull('stage2_at')->orWhere('stage2_at', '<', now()->subHours($ttlHours)))
                ->orderByDesc('stage1_score')
                ->limit($shortlistSize)
                ->count();

            if ($staleCount > 0) {
                try {
                    RerankShortlist::dispatchSync($profile->id, $shortlistSize, $request->user()->id);
                } catch (\Throwable $e) {
                    Log::warning('Feed sync-rerank failed', [
                        'profile_id' => $profile->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $profile->load('resume:id,name');

        $savedJobIds = DB::table('applications')
            ->where('user_id', $request->user()->id)
            ->pluck('canonical_job_id')
            ->flip();

        $query = DB::table('job_matches')
            ->join('canonical_jobs', 'job_matches.canonical_job_id', '=', 'canonical_jobs.id')
            ->where('job_matches.search_profile_id', $profile->id)
            ->whereNull('job_matches.dismissed_at');

        if ($raw) {
            $query->orderByDesc('job_matches.stage1_score');
        } else {
            $query->orderByRaw('job_matches.stage2_score DESC NULLS LAST')
                ->orderByDesc('job_matches.stage1_score');
        }

        $page = $query
            ->select([
                'job_matches.id as match_id',
                'job_matches.stage1_score',
                'job_matches.stage2_score',
                'job_matches.stage2_rationale',
                'job_matches.stage2_gaps',
                'job_matches.stage2_narrative',
                'job_matches.stage2_at',
                'canonical_jobs.id as job_id',
                'canonical_jobs.title',
                'canonical_jobs.company_name',
                'canonical_jobs.location_raw',
                'canonical_jobs.remote_mode',
                'canonical_jobs.comp_min',
                'canonical_jobs.comp_max',
                'canonical_jobs.comp_currency',
                'canonical_jobs.posted_at',
                'canonical_jobs.apply_url',
            ])
            ->paginate(20);

        $data = $page->through(fn ($row) => [
            'match_id' => $row->match_id,
            'stage1_score' => (float) $row->stage1_score,
            'stage2_score' => $row->stage2_score !== null ? (int) $row->stage2_score : null,
            'stage2_rationale' => $row->stage2_rationale,
            'stage2_gaps' => $row->stage2_gaps ? json_decode($row->stage2_gaps, true) : null,
            'stage2_narrative' => $row->stage2_narrative,
            'stage2_at' => $row->stage2_at,
            'is_saved' => $savedJobIds->has($row->job_id),
            'job' => [
                'id' => $row->job_id,
                'title' => $row->title,
                'company_name' => $row->company_name,
                'location_raw' => $row->location_raw,
                'remote_mode' => $row->remote_mode,
                'comp_min' => $row->comp_min,
                'comp_max' => $row->comp_max,
                'comp_currency' => $row->comp_currency,
                'posted_at' => $row->posted_at,
                'apply_url' => $row->apply_url,
            ],
        ]);

        return Inertia::render('feed/Show', [
            'profile' => [
                'id' => $profile->id,
                'name' => $profile->name,
                'resume' => $profile->resume ? ['id' => $profile->resume->id, 'name' => $profile->resume->name] : null,
            ],
            'matches' => $data,
            'showDebug' => $request->user()->isAdmin() && $request->boolean('debug'),
            'rawMode' => $raw,
        ]);
    }
}
