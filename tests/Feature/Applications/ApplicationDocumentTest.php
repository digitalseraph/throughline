<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\User;
use App\Services\Applications\EventWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicationDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_lands_on_disk_and_writes_event(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();
        $file = UploadedFile::fake()->create('cover.pdf', 50, 'application/pdf');

        $this->actingAs($user)
            ->post(route('applications.documents.store', $app), ['kind' => 'cover_letter', 'file' => $file])
            ->assertRedirect();

        $doc = $app->documents()->first();
        $this->assertSame('cover_letter', $doc->kind);
        Storage::disk('local')->assertExists($doc->file_path);
        $this->assertSame(1, $app->events()->where('type', 'document_attached')->count());
    }

    public function test_delete_removes_file_and_row(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();
        Storage::disk('local')->put('applications/stub.pdf', 'x');
        $doc = ApplicationDocument::factory()->for($app)->create(['file_path' => 'applications/stub.pdf']);

        $this->actingAs($user)->delete(route('applications.documents.destroy', [$app, $doc]))->assertRedirect();

        $this->assertNull($doc->fresh());
        Storage::disk('local')->assertMissing('applications/stub.pdf');
    }

    public function test_mime_validation_rejects_bad_files(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();
        $file = UploadedFile::fake()->create('bad.exe', 10, 'application/octet-stream');

        $this->actingAs($user)
            ->post(route('applications.documents.store', $app), ['kind' => 'cover_letter', 'file' => $file])
            ->assertSessionHasErrors('file');
    }

    public function test_transaction_rollback_does_not_orphan_file_in_db(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();
        $file = UploadedFile::fake()->create('cover.pdf', 50, 'application/pdf');

        // Simulate a transaction failure by mocking EventWriter to throw AFTER the
        // documents()->create() would have run. Use partial mock on EventWriter.
        $this->mock(EventWriter::class, function ($mock) {
            $mock->shouldReceive('logDocumentAttached')->andThrow(new \RuntimeException('simulated'));
        });

        try {
            $this->actingAs($user)->post(route('applications.documents.store', $app), [
                'kind' => 'cover_letter',
                'file' => $file,
            ]);
        } catch (\RuntimeException $e) {
            // expected
        }

        // No DB row should exist — transaction rolled back
        $this->assertSame(0, $app->documents()->count());
        $this->assertSame(0, $app->events()->where('type', 'document_attached')->count());
    }

    public function test_stranger_forbidden(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $app = Application::factory()->for($owner)->create();
        $file = UploadedFile::fake()->create('cover.pdf', 50, 'application/pdf');

        $this->actingAs($stranger)
            ->post(route('applications.documents.store', $app), ['kind' => 'cover_letter', 'file' => $file])
            ->assertForbidden();
    }
}
