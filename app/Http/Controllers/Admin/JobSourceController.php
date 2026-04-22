<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreJobSourceRequest;
use App\Http\Requests\Admin\UpdateJobSourceRequest;
use App\Jobs\FetchJobSource;
use App\Models\JobSource;
use App\Services\Jobs\DriverRegistry;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class JobSourceController extends Controller
{
    public function index(DriverRegistry $registry): Response
    {
        return Inertia::render('admin/job-sources/Index', [
            'sources' => JobSource::orderBy('source_type')->orderBy('identifier')->get(),
            'driverTypes' => $registry->supportedTypes(),
        ]);
    }

    public function create(DriverRegistry $registry): Response
    {
        return Inertia::render('admin/job-sources/Edit', [
            'source' => new JobSource(['source_type' => $registry->supportedTypes()[0], 'fetch_cadence_minutes' => 60, 'is_enabled' => true]),
            'driverTypes' => $registry->supportedTypes(),
        ]);
    }

    public function edit(JobSource $job_source, DriverRegistry $registry): Response
    {
        return Inertia::render('admin/job-sources/Edit', [
            'source' => $job_source,
            'driverTypes' => $registry->supportedTypes(),
        ]);
    }

    public function store(StoreJobSourceRequest $request): RedirectResponse
    {
        JobSource::create($request->validated() + ['consecutive_failures' => 0]);

        return redirect()->route('admin.job-sources.index')->with('status', 'Source created.');
    }

    public function update(UpdateJobSourceRequest $request, JobSource $job_source): RedirectResponse
    {
        $job_source->update($request->validated());

        return redirect()->route('admin.job-sources.index')->with('status', 'Source updated.');
    }

    public function destroy(JobSource $job_source): RedirectResponse
    {
        $job_source->delete();

        return redirect()->route('admin.job-sources.index')->with('status', 'Source deleted.');
    }

    public function fetchNow(JobSource $job_source): RedirectResponse
    {
        FetchJobSource::dispatch($job_source->id);

        return back()->with('status', "Fetch queued for {$job_source->display_name}.");
    }
}
