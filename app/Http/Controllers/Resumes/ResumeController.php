<?php

namespace App\Http\Controllers\Resumes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Resumes\StoreResumeRequest;
use App\Http\Requests\Resumes\UpdateResumeRequest;
use App\Jobs\ParseResume;
use App\Models\Resume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ResumeController extends Controller
{
    public function index(): Response
    {
        $resumes = auth()->user()->resumes()
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'parse_status', 'is_default', 'created_at']);

        return Inertia::render('resumes/Index', [
            'resumes' => $resumes,
        ]);
    }

    public function show(Resume $resume): Response
    {
        abort_unless($resume->user_id === auth()->id(), 403);

        $hasEmbedding = DB::table('resumes')->where('id', $resume->id)->whereNotNull('embedding')->exists();

        return Inertia::render('resumes/Show', [
            'resume' => [
                'id' => $resume->id,
                'name' => $resume->name,
                'parse_status' => $resume->parse_status,
                'is_default' => $resume->is_default,
                'has_embedding' => $hasEmbedding,
                'parsed_text' => $resume->parsed_text,
                'structured_json' => $resume->structured_json,
                'created_at' => $resume->created_at,
            ],
        ]);
    }

    public function store(StoreResumeRequest $request): RedirectResponse
    {
        $file = $request->file('file');
        $path = $file->store('resumes/'.auth()->id());

        $resume = auth()->user()->resumes()->create([
            'name' => $request->string('name'),
            'source_file_path' => $path,
            'source_mime' => $file->getMimeType(),
            'parse_status' => 'pending',
            'is_default' => auth()->user()->resumes()->count() === 0,
        ]);

        ParseResume::dispatch($resume->id);

        return redirect()->route('resumes.show', $resume)->with('status', 'Resume uploaded. Parsing…');
    }

    public function update(UpdateResumeRequest $request, Resume $resume): RedirectResponse
    {
        $resume->update(['name' => $request->string('name')]);

        return back()->with('status', 'Resume renamed.');
    }

    public function destroy(Resume $resume): RedirectResponse
    {
        abort_unless($resume->user_id === auth()->id(), 403);

        $filePath = $resume->source_file_path;
        $resume->delete();

        if ($filePath) {
            // Best-effort cleanup — a leaked file is recoverable, but a
            // DB row pointing at a missing file would 500 any stream/stat.
            try {
                Storage::delete($filePath);
            } catch (\Throwable $e) {
                \Log::warning('Resume file cleanup failed', ['path' => $filePath, 'error' => $e->getMessage()]);
            }
        }

        return redirect()->route('resumes.index')->with('status', 'Resume deleted.');
    }

    public function setDefault(Resume $resume): RedirectResponse
    {
        abort_unless($resume->user_id === auth()->id(), 403);

        DB::transaction(function () use ($resume) {
            auth()->user()->resumes()->where('id', '!=', $resume->id)->update(['is_default' => false]);
            $resume->update(['is_default' => true]);
        });

        return back()->with('status', 'Default resume updated.');
    }

    public function retry(Resume $resume): RedirectResponse
    {
        abort_unless($resume->user_id === auth()->id(), 403);
        $resume->update(['parse_status' => 'pending']);
        ParseResume::dispatch($resume->id);

        return back()->with('status', 'Parse retry queued.');
    }
}
