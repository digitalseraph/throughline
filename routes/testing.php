<?php

use App\Jobs\RecomputeStage1ForResume;
use App\Models\CanonicalJob;
use App\Models\SearchProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('/testing/seed-jobs', function () {
    $jobs = [
        ['title' => 'Staff Frontend Engineer', 'company_name' => 'Acme', 'remote_mode' => 'remote_only'],
        ['title' => 'Senior Platform Engineer', 'company_name' => 'Beta', 'remote_mode' => 'hybrid_ok'],
        ['title' => 'Principal Engineer', 'company_name' => 'Gamma', 'remote_mode' => 'onsite'],
    ];
    foreach ($jobs as $attrs) {
        $job = CanonicalJob::create([
            'source' => 'test',
            'source_id' => 'seed-'.uniqid(),
            'source_board' => 'test',
            'company_name' => $attrs['company_name'],
            'title' => $attrs['title'],
            'description' => 'Seeded for E2E smoke.',
            'remote_mode' => $attrs['remote_mode'],
            'apply_url' => 'https://example.test/job/'.uniqid(),
            'posted_at' => now(),
            'last_seen_at' => now(),
        ]);
        DB::statement('UPDATE canonical_jobs SET embedding = ?::vector WHERE id = ?', [
            '['.implode(',', array_fill(0, 1536, 0.5)).']',
            $job->id,
        ]);
    }

    return response()->json(['seeded' => count($jobs)]);
});

Route::post('/testing/run-matching/{profileId}', function ($profileId) {
    RecomputeStage1ForResume::dispatchSync(
        SearchProfile::findOrFail($profileId)->resume_id
    );

    return response()->json(['ok' => true]);
});
