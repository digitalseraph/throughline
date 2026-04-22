<?php

namespace App\Http\Controllers;

use App\Models\SearchProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FeedController extends Controller
{
    public function show(Request $request, SearchProfile $profile): Response
    {
        abort_unless((int) $profile->user_id === $request->user()->id, 403);

        $profile->load('resume:id,name');

        $page = DB::table('job_matches')
            ->join('canonical_jobs', 'job_matches.canonical_job_id', '=', 'canonical_jobs.id')
            ->where('job_matches.search_profile_id', $profile->id)
            ->whereNull('job_matches.dismissed_at')
            ->orderByDesc('job_matches.stage1_score')
            ->select([
                'job_matches.id as match_id',
                'job_matches.stage1_score',
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
        ]);
    }
}
