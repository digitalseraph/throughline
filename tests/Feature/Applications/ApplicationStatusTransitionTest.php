<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_patch_status_moves_and_writes_event(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create(['status' => 'saved']);

        $this->actingAs($user)->patch(route('applications.update', $app), ['status' => 'applied'])->assertRedirect();

        $app->refresh();
        $this->assertSame('applied', $app->status);
        $this->assertNotNull($app->applied_at);

        $event = $app->events()->where('type', 'status_change')->first();
        $this->assertSame('saved', $event->payload['from']);
        $this->assertSame('applied', $event->payload['to']);
    }

    public function test_patch_preserves_applied_at_on_later_transitions(): void
    {
        $user = User::factory()->create();
        $appliedTime = now()->subDays(3);
        $app = Application::factory()->for($user)->create(['status' => 'applied', 'applied_at' => $appliedTime]);

        $this->actingAs($user)->patch(route('applications.update', $app), ['status' => 'phone_screen']);

        $app->refresh();
        $this->assertEquals($appliedTime->toDateTimeString(), $app->applied_at->toDateTimeString());
    }

    public function test_same_status_is_noop_no_event_written(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create(['status' => 'saved']);

        $this->actingAs($user)->patch(route('applications.update', $app), ['status' => 'saved'])->assertRedirect();

        $this->assertSame(0, $app->events()->count());
    }

    public function test_invalid_status_rejected(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create(['status' => 'saved']);

        $this->actingAs($user)
            ->patch(route('applications.update', $app), ['status' => 'bogus'])
            ->assertSessionHasErrors('status');
    }

    public function test_stranger_cannot_transition(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $app = Application::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->patch(route('applications.update', $app), ['status' => 'applied'])
            ->assertForbidden();
    }
}
