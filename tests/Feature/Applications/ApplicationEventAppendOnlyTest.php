<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ApplicationEventAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_an_event_throws_logicexception(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();
        $event = ApplicationEvent::factory()->create(['application_id' => $app->id]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('append-only');

        $event->update(['type' => 'note']);
    }

    public function test_save_of_new_event_is_allowed(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();

        $event = ApplicationEvent::create([
            'application_id' => $app->id,
            'type' => 'status_change',
            'payload' => ['from' => null, 'to' => 'saved'],
            'occurred_at' => now(),
        ]);

        $this->assertNotNull($event->id);
        $this->assertSame('status_change', $event->fresh()->type);
    }
}
