<?php

namespace App\Http\Controllers\Admin;

use App\Models\Application;
use App\Models\CanonicalJob;
use App\Models\JobSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class JobController extends BaseController
{
    public function index(Request $request): Response
    {
        $query = CanonicalJob::query()
            ->orderByDesc('posted_at')
            ->select('id', 'source', 'source_board', 'company_name', 'title', 'location_raw', 'remote_mode', 'posted_at', 'closed_at');

        $source = $request->string('source')->toString();
        $company = $request->string('company')->toString();

        if ($source) {
            $query->where('source', $source);
        }
        if ($company) {
            $query->where('company_name', 'ilike', "%{$company}%");
        }
        if ($request->boolean('has_embedding')) {
            $query->whereNotNull('embedding');
        }
        if ($request->boolean('closed_only')) {
            $query->whereNotNull('closed_at');
        }

        return Inertia::render('admin/jobs/Index', [
            'jobs' => $query->paginate(50)->withQueryString(),
            'filters' => [
                'source' => $source,
                'company' => $company,
                'has_embedding' => $request->boolean('has_embedding'),
                'closed_only' => $request->boolean('closed_only'),
            ],
            'sourceTypes' => JobSource::distinct()->pluck('source_type')->toArray(),
        ]);
    }

    public function show(CanonicalJob $canonical_job): Response
    {
        $matches = DB::table('job_matches')
            ->join('users', 'job_matches.user_id', '=', 'users.id')
            ->where('job_matches.canonical_job_id', $canonical_job->id)
            ->select('job_matches.id', 'users.email as user_email', 'job_matches.stage1_score', 'job_matches.stage2_score', 'job_matches.created_at')
            ->orderByDesc('job_matches.stage1_score')
            ->get();

        $applications = Application::where('canonical_job_id', $canonical_job->id)
            ->with('user:id,email')
            ->get(['id', 'user_id', 'status', 'created_at']);

        return Inertia::render('admin/jobs/Show', [
            'job' => $canonical_job,
            'matches' => $matches,
            'applications' => $applications,
        ]);
    }
}
