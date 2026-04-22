<?php

namespace App\Http\Controllers\Applications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\StoreDocumentRequest;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Services\Applications\EventWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function __construct(protected EventWriter $events) {}

    public function store(StoreDocumentRequest $request, Application $application): RedirectResponse
    {
        $file = $request->file('file');
        $path = $file->store('applications/'.$application->user_id.'/'.$application->id, 'local');

        DB::transaction(function () use ($application, $file, $path, $request) {
            $doc = $application->documents()->create([
                'kind' => $request->input('kind'),
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'uploaded_at' => now(),
            ]);

            $this->events->logDocumentAttached($application, $doc);
        });

        return redirect()->back()->with('status', 'Document attached.');
    }

    public function destroy(Application $application, ApplicationDocument $document, Request $request): RedirectResponse
    {
        abort_unless((int) $application->user_id === $request->user()->id, 403);
        abort_unless($document->application_id === $application->id, 404);

        $filePath = $document->file_path;
        $document->delete();

        if ($filePath) {
            try {
                Storage::disk('local')->delete($filePath);
            } catch (\Throwable $e) {
                Log::warning('Document file cleanup failed', ['path' => $filePath, 'error' => $e->getMessage()]);
            }
        }

        return redirect()->back()->with('status', 'Document removed.');
    }
}
