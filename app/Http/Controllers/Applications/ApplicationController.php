<?php

namespace App\Http\Controllers\Applications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\StoreApplicationRequest;
use App\Models\Application;
use App\Models\JobMatch;
use App\Services\Applications\EventWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class ApplicationController extends Controller
{
    public function __construct(protected EventWriter $events) {}

    public function store(StoreApplicationRequest $request): RedirectResponse|JsonResponse
    {
        $match = JobMatch::with('profile')->findOrFail($request->integer('job_match_id'));

        abort_unless((int) $match->user_id === $request->user()->id, 403);

        if (! $match->profile?->resume_id) {
            return response()->json(['message' => 'Link a resume to this search profile before saving.'], 422);
        }

        $existing = Application::where('user_id', $match->user_id)
            ->where('canonical_job_id', $match->canonical_job_id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Already saved.', 'application_id' => $existing->id], 409);
        }

        $snapshot = [
            'fit_score' => $match->stage2_score,
            'rationale' => $match->stage2_rationale,
            'gaps' => $match->stage2_gaps,
            'narrative' => $match->stage2_narrative,
            'stage1_score' => (float) $match->stage1_score,
            'snapshot_at' => now()->toIso8601String(),
        ];

        $app = DB::transaction(function () use ($match, $snapshot) {
            $app = Application::create([
                'user_id' => $match->user_id,
                'canonical_job_id' => $match->canonical_job_id,
                'search_profile_id' => $match->search_profile_id,
                'resume_id' => $match->profile->resume_id,
                'status' => 'saved',
                'stage2_snapshot' => $snapshot,
            ]);

            $this->events->logStatusChange($app, null, 'saved');

            return $app;
        });

        return redirect()->back()->with('status', 'Saved to your applications.')->with('applicationId', $app->id);
    }
}
