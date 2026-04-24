<?php

namespace App\Jobs;

use App\Events\ResumeParseFailed;
use App\Models\Resume;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\ParseFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory as WordIO;
use Spatie\PdfToText\Pdf as PdfToText;

class ParseResume implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $resumeId) {}

    public function handle(AiClient $ai): void
    {
        $resume = Resume::findOrFail($this->resumeId);
        if ($resume->parse_status === 'parsed') {
            return; // idempotent
        }

        try {
            $rawText = $this->extractRawText($resume);
        } catch (\Throwable $e) {
            // PDF/DOCX extraction failed deterministically (bad file, missing
            // binary). Retrying won't help; surface a clear state to the UI.
            $resume->parsed_text = '';
            $resume->parse_status = 'parse_failed';
            $resume->save();

            try {
                event(new ResumeParseFailed($resume->fresh()));
            } catch (\Throwable $broadcastException) {
                Log::warning('ResumeParseFailed broadcast failed', ['resume_id' => $resume->id, 'error' => $broadcastException->getMessage()]);
            }

            return;
        }

        $resume->parsed_text = $rawText;
        $resume->save();

        try {
            $result = $ai->forUser($resume->user)->parseResume($rawText);
        } catch (ParseFailed $e) {
            $resume->parse_status = 'parse_failed';
            $resume->save();

            try {
                event(new ResumeParseFailed($resume->fresh()));
            } catch (\Throwable $broadcastException) {
                Log::warning('ResumeParseFailed broadcast failed', ['resume_id' => $resume->id, 'error' => $broadcastException->getMessage()]);
            }

            return;
        }

        $resume->parsed_text = $result['text'];
        $resume->structured_json = $result['structured'];
        $resume->parse_status = 'parsed';
        $resume->save();

        EmbedResume::dispatch($resume->id);
    }

    protected function extractRawText(Resume $resume): string
    {
        // pdftotext and phpWord both need a real local file path, so we pull the
        // upload from whichever disk it lives on (local in dev, s3 in prod) into
        // a temp file, parse it, and clean up. Using Storage (default disk)
        // rather than Storage::disk('local') is the fix.
        if (! Storage::exists($resume->source_file_path)) {
            throw new \RuntimeException("Resume source not found on configured disk: {$resume->source_file_path}");
        }

        $ext = pathinfo($resume->source_file_path, PATHINFO_EXTENSION) ?: 'bin';
        $tmpPath = tempnam(sys_get_temp_dir(), 'resume_').'.'.$ext;
        file_put_contents($tmpPath, Storage::get($resume->source_file_path));

        try {
            return match ($resume->source_mime) {
                'application/pdf' => PdfToText::getText($tmpPath, config('services.pdftotext.binary')),
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->extractDocx($tmpPath),
                default => file_get_contents($tmpPath) ?: '',
            };
        } finally {
            @unlink($tmpPath);
        }
    }

    protected function extractDocx(string $path): string
    {
        $phpWord = WordIO::load($path);
        $text = '';
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $text .= $element->getText()."\n";
                }
            }
        }

        return trim($text);
    }
}
