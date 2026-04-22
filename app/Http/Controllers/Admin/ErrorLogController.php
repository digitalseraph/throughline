<?php

namespace App\Http\Controllers\Admin;

use App\Jobs\EmbedJob;
use App\Jobs\EmbedResume;
use App\Jobs\ParseResume;
use App\Jobs\RerankShortlist;
use App\Models\CanonicalJob;
use App\Models\JobMatch;
use App\Models\Resume;
use App\Services\Admin\ErrorLog;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ErrorLogController extends BaseController
{
    public function show(ErrorLog $log): Response
    {
        return Inertia::render('admin/Errors', [
            'failedParses' => $log->failedParses(),
            'stuckResumeEmbeddings' => $log->stuckResumeEmbeddings(),
            'stuckJobEmbeddings' => $log->stuckJobEmbeddings(),
            'rerankUnavailable' => $log->rerankUnavailable(),
        ]);
    }

    public function retryResumeParse(Resume $resume): RedirectResponse
    {
        $resume->update(['parse_status' => 'pending']);
        ParseResume::dispatch($resume->id);
        $this->audit('resume.parse_retried', $resume);

        return redirect()->back();
    }

    public function retryResumeEmbed(Resume $resume): RedirectResponse
    {
        EmbedResume::dispatch($resume->id);
        $this->audit('resume.embed_retried', $resume);

        return redirect()->back();
    }

    public function retryJobEmbed(CanonicalJob $canonical_job): RedirectResponse
    {
        EmbedJob::dispatch($canonical_job->id);
        $this->audit('job.embed_retried', $canonical_job);

        return redirect()->back();
    }

    public function retryRerank(JobMatch $match): RedirectResponse
    {
        RerankShortlist::dispatch($match->search_profile_id, 20, null);
        $this->audit('match.rerank_retried', $match);

        return redirect()->back();
    }
}
