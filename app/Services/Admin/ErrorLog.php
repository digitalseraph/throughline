<?php

namespace App\Services\Admin;

use App\Models\Resume;
use Illuminate\Support\Facades\DB;

class ErrorLog
{
    public function failedParses(int $limit = 50): array
    {
        return Resume::where('parse_status', 'parse_failed')
            ->with('user:id,email')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public function stuckResumeEmbeddings(int $limit = 50): array
    {
        return DB::table('resumes')
            ->where('parse_status', 'parsed')
            ->whereNull('embedding')
            ->where('updated_at', '<', now()->subHour())
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public function stuckJobEmbeddings(int $limit = 50): array
    {
        return DB::table('canonical_jobs')
            ->whereNull('embedding')
            ->whereNull('closed_at')
            ->where('created_at', '<', now()->subHour())
            ->limit($limit)
            ->get(['id', 'source', 'source_id', 'company_name', 'title', 'created_at'])
            ->toArray();
    }

    public function rerankUnavailable(int $limit = 50): array
    {
        return DB::table('job_matches')
            ->where('stage2_rationale', 'rerank unavailable')
            ->where('stage2_at', '<', now()->subHours(6))
            ->limit($limit)
            ->get(['id', 'user_id', 'search_profile_id', 'canonical_job_id', 'stage2_at'])
            ->toArray();
    }
}
