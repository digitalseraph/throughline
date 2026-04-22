<?php

namespace Tests\Feature\Events;

use App\Events\ReminderBadgeUpdated;
use App\Jobs\ApplicationReminderScan;
use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ReminderBadgeUpdatedBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_reminder_scan_fires_event_with_correct_count(): void
    {
        Event::fake([ReminderBadgeUpdated::class]);

        $user = User::factory()->create();

        // Two overdue non-terminal apps
        Application::factory()->for($user)->create([
            'status' => 'applied',
            'next_action_at' => now()->subDays(2),
        ]);
        Application::factory()->for($user)->create([
            'status' => 'applied',
            'next_action_at' => now()->subDays(1),
        ]);

        // Terminal — should not be counted
        Application::factory()->for($user)->create([
            'status' => 'rejected',
            'next_action_at' => now()->subDays(1),
        ]);

        (new ApplicationReminderScan)->handle();

        Event::assertDispatched(ReminderBadgeUpdated::class, function (ReminderBadgeUpdated $event) use ($user) {
            return $event->userId === $user->id && $event->count === 2;
        });
    }

    public function test_application_update_fires_event_when_next_action_at_changes(): void
    {
        Event::fake([ReminderBadgeUpdated::class]);

        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create([
            'status' => 'applied',
            'next_action_at' => null,
        ]);

        $this->actingAs($user)
            ->patch(route('applications.update', $app->id), [
                'next_action_at' => now()->subDay()->toDateString(),
            ])
            ->assertRedirect();

        Event::assertDispatched(ReminderBadgeUpdated::class, function (ReminderBadgeUpdated $event) use ($user) {
            return $event->userId === $user->id && $event->count === 1;
        });
    }
}
