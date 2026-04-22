<?php

namespace App\Services\Applications;

use App\Models\Application;
use App\Models\ApplicationContact;
use App\Models\ApplicationDocument;
use App\Models\ApplicationEvent;

class EventWriter
{
    public function logStatusChange(Application $app, ?string $from, string $to): ApplicationEvent
    {
        return $this->create($app, 'status_change', ['from' => $from, 'to' => $to]);
    }

    public function logNote(Application $app, string $body): ApplicationEvent
    {
        return $this->create($app, 'note', ['body' => $body]);
    }

    public function logContactAdded(Application $app, ApplicationContact $contact): ApplicationEvent
    {
        return $this->create($app, 'contact_added', ['contact_id' => $contact->id, 'role' => $contact->role]);
    }

    public function logDocumentAttached(Application $app, ApplicationDocument $doc): ApplicationEvent
    {
        return $this->create($app, 'document_attached', ['document_id' => $doc->id, 'kind' => $doc->kind]);
    }

    public function logReminder(Application $app): ApplicationEvent
    {
        return $this->create($app, 'reminder', ['next_action_at' => $app->next_action_at?->toIso8601String()]);
    }

    public function logInterview(Application $app, array $details): ApplicationEvent
    {
        return $this->create($app, 'interview', $details);
    }

    protected function create(Application $app, string $type, array $payload): ApplicationEvent
    {
        return ApplicationEvent::create([
            'application_id' => $app->id,
            'type' => $type,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }
}
