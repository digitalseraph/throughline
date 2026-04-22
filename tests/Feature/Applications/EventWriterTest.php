<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\ApplicationContact;
use App\Models\ApplicationDocument;
use App\Models\User;
use App\Services\Applications\EventWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventWriterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = app(EventWriter::class);
    }

    protected EventWriter $writer;

    public function test_log_status_change_writes_row_with_from_to_payload(): void
    {
        $app = Application::factory()->for(User::factory())->create(['status' => 'applied']);

        $event = $this->writer->logStatusChange($app, 'saved', 'applied');

        $this->assertSame('status_change', $event->type);
        $this->assertSame(['from' => 'saved', 'to' => 'applied'], $event->payload);
        $this->assertSame($app->id, $event->application_id);
    }

    public function test_log_status_change_handles_null_from(): void
    {
        $app = Application::factory()->for(User::factory())->create();

        $event = $this->writer->logStatusChange($app, null, 'saved');

        $this->assertSame(['from' => null, 'to' => 'saved'], $event->payload);
    }

    public function test_log_note_writes_body_payload(): void
    {
        $app = Application::factory()->for(User::factory())->create();

        $event = $this->writer->logNote($app, 'Had coffee with recruiter');

        $this->assertSame('note', $event->type);
        $this->assertSame(['body' => 'Had coffee with recruiter'], $event->payload);
    }

    public function test_log_contact_added_captures_contact_id_and_role(): void
    {
        $app = Application::factory()->for(User::factory())->create();
        $contact = ApplicationContact::factory()->for($app)->create(['role' => 'recruiter']);

        $event = $this->writer->logContactAdded($app, $contact);

        $this->assertSame('contact_added', $event->type);
        $this->assertSame(['contact_id' => $contact->id, 'role' => 'recruiter'], $event->payload);
    }

    public function test_log_document_attached_captures_document_id_and_kind(): void
    {
        $app = Application::factory()->for(User::factory())->create();
        $doc = ApplicationDocument::factory()->for($app)->create(['kind' => 'cover_letter']);

        $event = $this->writer->logDocumentAttached($app, $doc);

        $this->assertSame('document_attached', $event->type);
        $this->assertSame(['document_id' => $doc->id, 'kind' => 'cover_letter'], $event->payload);
    }

    public function test_log_reminder_writes_current_next_action_at(): void
    {
        $app = Application::factory()->for(User::factory())->create(['next_action_at' => now()->subDay()]);

        $event = $this->writer->logReminder($app);

        $this->assertSame('reminder', $event->type);
        $this->assertArrayHasKey('next_action_at', $event->payload);
    }

    public function test_log_interview_captures_details_payload(): void
    {
        $app = Application::factory()->for(User::factory())->create();

        $event = $this->writer->logInterview($app, ['stage' => 'phone_screen', 'scheduled_for' => '2026-05-01T10:00:00Z']);

        $this->assertSame('interview', $event->type);
        $this->assertSame('phone_screen', $event->payload['stage']);
    }
}
