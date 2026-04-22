<?php

namespace Tests\Feature\Applications;

use App\Jobs\ApplicationReminderScan;
use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationReminderScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_applications_get_reminder_events(): void
    {
        $user = User::factory()->create();
        $overdue = Application::factory()->for($user)->create([
            'status' => 'applied',
            'next_action_at' => now()->subDays(2),
        ]);
        $notDueYet = Application::factory()->for($user)->create([
            'status' => 'applied',
            'next_action_at' => now()->addDays(2),
        ]);
        $terminal = Application::factory()->for($user)->create([
            'status' => 'rejected',
            'next_action_at' => now()->subDays(2),
        ]);

        (new ApplicationReminderScan)->handle();

        $this->assertSame(1, $overdue->events()->where('type', 'reminder')->count());
        $this->assertSame(0, $notDueYet->events()->where('type', 'reminder')->count());
        $this->assertSame(0, $terminal->events()->where('type', 'reminder')->count());
    }

    public function test_same_day_rerun_does_not_duplicate_reminder(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create([
            'status' => 'applied',
            'next_action_at' => now()->subDays(1),
        ]);

        (new ApplicationReminderScan)->handle();
        (new ApplicationReminderScan)->handle();

        $this->assertSame(1, $app->events()->where('type', 'reminder')->count());
    }
}
