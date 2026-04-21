<?php

namespace App\Jobs;

use App\Models\Resume;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\ParseFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

        $rawText = $this->extractRawText($resume);
        $resume->parsed_text = $rawText;
        $resume->save();

        try {
            $result = $ai->forUser($resume->user)->parseResume($rawText);
        } catch (ParseFailed $e) {
            $resume->parse_status = 'parse_failed';
            $resume->save();

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
        $diskPath = Storage::disk('local')->path($resume->source_file_path);

        return match ($resume->source_mime) {
            'application/pdf' => PdfToText::getText($diskPath),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->extractDocx($diskPath),
            default => file_get_contents($diskPath) ?: '',
        };
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
