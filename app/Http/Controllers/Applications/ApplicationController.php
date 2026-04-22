<?php

namespace App\Http\Controllers\Applications;

use App\Events\ReminderBadgeUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\StoreApplicationRequest;
use App\Models\Application;
use App\Models\JobMatch;
use App\Services\Applications\EventWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ApplicationController extends Controller
{
    public function __construct(protected EventWriter $events) {}

    public function index(Request $request): InertiaResponse
    {
        $statuses = Application::STATUSES;
        $terminalStatuses = Application::TERMINAL_STATUSES;

        $apps = Application::with('canonicalJob')
            ->where('user_id', $request->user()->id)
            ->orderBy('updated_at', 'desc')
            ->get();

        $columns = collect($statuses)->mapWithKeys(fn ($s) => [
            $s => $apps->where('status', $s)->values()->map(fn ($app) => [
                'id' => $app->id,
                'title' => $app->canonicalJob?->title ?? '—',
                'company' => $app->canonicalJob?->company_name ?? '—',
                'status' => $app->status,
                'next_action_at' => $app->next_action_at?->toDateString(),
                'updated_at' => $app->updated_at->toDateTimeString(),
            ])->all(),
        ])->all();

        return Inertia::render('applications/Index', compact('columns', 'statuses', 'terminalStatuses'));
    }

    public function show(Request $request, Application $application): InertiaResponse
    {
        abort_unless((int) $application->user_id === $request->user()->id, 403);

        $application->load('canonicalJob', 'resume', 'events', 'contacts', 'documents');

        return Inertia::render('applications/Show', [
            'application' => [
                'id' => $application->id,
                'status' => $application->status,
                'notes' => $application->notes,
                'applied_at' => $application->applied_at?->toDateTimeString(),
                'next_action_at' => $application->next_action_at?->toDateTimeString(),
                'stage2_snapshot' => $application->stage2_snapshot,
                'is_terminal' => $application->isTerminal(),
                'canonical_job' => $application->canonicalJob ? [
                    'id' => $application->canonicalJob->id,
                    'title' => $application->canonicalJob->title,
                    'company_name' => $application->canonicalJob->company_name,
                    'location_raw' => $application->canonicalJob->location_raw,
                    'apply_url' => $application->canonicalJob->apply_url,
                ] : null,
                'resume' => $application->resume ? [
                    'id' => $application->resume->id,
                    'name' => $application->resume->name,
                ] : null,
                'events' => $application->events->map(fn ($e) => [
                    'id' => $e->id,
                    'type' => $e->type,
                    'payload' => $e->payload,
                    'occurred_at' => $e->occurred_at->toDateTimeString(),
                ])->all(),
                'contacts' => $application->contacts->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'role' => $c->role,
                ])->all(),
                'documents' => $application->documents->map(fn ($d) => [
                    'id' => $d->id,
                    'kind' => $d->kind,
                    'label' => $d->label,
                ])->all(),
            ],
            'statuses' => Application::STATUSES,
            'terminalStatuses' => Application::TERMINAL_STATUSES,
        ]);
    }

    public function update(Request $request, Application $application): RedirectResponse
    {
        abort_unless((int) $application->user_id === $request->user()->id, 403);

        $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', Application::STATUSES)],
            'notes' => ['nullable', 'string'],
            'next_action_at' => ['nullable', 'date'],
        ]);

        $shouldFireBadge = false;

        DB::transaction(function () use ($request, $application, &$shouldFireBadge) {
            $oldStatus = $application->status;
            $newStatus = $request->input('status');
            $newNotes = $request->input('notes');
            $newNextAction = $request->input('next_action_at');

            $dirty = [];

            if ($newStatus !== null && $newStatus !== $oldStatus) {
                $dirty['status'] = $newStatus;
                if ($newStatus === 'applied' && $application->applied_at === null) {
                    $dirty['applied_at'] = now();
                }
            }

            if ($newNotes !== null) {
                $dirty['notes'] = $newNotes;
            }

            if (array_key_exists('next_action_at', $request->all())) {
                $dirty['next_action_at'] = $newNextAction;
            }

            if ($dirty) {
                $application->update($dirty);
            }

            if ($newStatus !== null && $newStatus !== $oldStatus) {
                $this->events->logStatusChange($application, $oldStatus, $newStatus);
            }

            if ($newNotes !== null && $newNotes !== '') {
                $this->events->logNote($application, $newNotes);
            }

            $statusTransitionedTerminal = $newStatus !== null && $newStatus !== $oldStatus
                && (in_array($newStatus, Application::TERMINAL_STATUSES) || in_array($oldStatus, Application::TERMINAL_STATUSES));

            $nextActionChanged = array_key_exists('next_action_at', $request->all());

            if ($statusTransitionedTerminal || $nextActionChanged) {
                $shouldFireBadge = true;
            }
        });

        if ($shouldFireBadge) {
            try {
                $userId = $application->user_id;
                $count = Application::where('user_id', $userId)
                    ->whereNotIn('status', Application::TERMINAL_STATUSES)
                    ->whereNotNull('next_action_at')
                    ->where('next_action_at', '<=', now())
                    ->count();

                ReminderBadgeUpdated::dispatch($userId, $count);
            } catch (\Throwable $e) {
                Log::warning('ReminderBadgeUpdated broadcast failed in update', [
                    'application_id' => $application->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return redirect()->back()->with('status', 'Application updated.');
    }

    public function destroy(Request $request, Application $application): RedirectResponse
    {
        abort_unless((int) $application->user_id === $request->user()->id, 403);

        DB::transaction(function () use ($application) {
            $old = $application->status;
            $application->update(['status' => 'withdrawn']);
            $this->events->logStatusChange($application, $old, 'withdrawn');
        });

        try {
            $userId = $application->user_id;
            $count = Application::where('user_id', $userId)
                ->whereNotIn('status', Application::TERMINAL_STATUSES)
                ->whereNotNull('next_action_at')
                ->where('next_action_at', '<=', now())
                ->count();

            ReminderBadgeUpdated::dispatch($userId, $count);
        } catch (\Throwable $e) {
            Log::warning('ReminderBadgeUpdated broadcast failed in destroy', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
        }

        return redirect()->route('applications.index')->with('status', 'Application withdrawn.');
    }

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
