<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ApplicationDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationDocumentFactory extends Factory
{
    protected $model = ApplicationDocument::class;

    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'kind' => 'cover_letter',
            'file_path' => 'applications/stub.pdf',
            'file_name' => 'cover-letter.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1024,
            'uploaded_at' => now(),
        ];
    }
}
