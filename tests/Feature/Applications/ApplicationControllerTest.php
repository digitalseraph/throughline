<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_shows_only_own_applications_grouped_by_status(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Application::factory()->for($user)->create(['status' => 'saved']);
        Application::factory()->for($user)->create(['status' => 'applied']);
        Application::factory()->for($other)->create(['status' => 'saved']);

        $response = $this->actingAs($user)
            ->get(route('applications.index'))
            ->assertOk();

        $props = $response->original->getData()['page']['props'];
        $columns = $props['columns'];

        // All 8 statuses present
        $this->assertCount(8, $columns);

        // Own apps grouped correctly
        $this->assertCount(1, $columns['saved']);
        $this->assertCount(1, $columns['applied']);

        // Stranger's app not included
        $allIds = collect($columns)->flatten(1)->pluck('id')->all();
        $otherId = Application::where('user_id', $other->id)->value('id');
        $this->assertNotContains($otherId, $allIds);
    }

    public function test_show_includes_event_timeline(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create(['status' => 'saved']);

        // Seed an event directly
        \DB::table('application_events')->insert([
            'application_id' => $app->id,
            'type' => 'status_change',
            'payload' => json_encode(['from' => null, 'to' => 'saved']),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('applications.show', $app->id))
            ->assertOk();

        $props = $response->original->getData()['page']['props'];
        $events = $props['application']['events'];

        $this->assertNotEmpty($events);
        $this->assertSame('status_change', $events[0]['type']);
    }

    public function test_show_forbids_stranger(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $app = Application::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->get(route('applications.show', $app->id))
            ->assertForbidden();
    }

    public function test_update_notes_writes_note_event(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create(['status' => 'saved']);

        $this->actingAs($user)
            ->patch(route('applications.update', $app->id), ['notes' => 'Contacted hiring manager.'])
            ->assertRedirect();

        $app->refresh();
        $this->assertSame('Contacted hiring manager.', $app->notes);

        $noteEvent = $app->events()->where('type', 'note')->first();
        $this->assertNotNull($noteEvent);
        $this->assertEquals('Contacted hiring manager.', $noteEvent->payload['body']);
    }

    public function test_destroy_writes_withdrawn_status_change_event(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create(['status' => 'applied']);

        $this->actingAs($user)
            ->delete(route('applications.destroy', $app->id))
            ->assertRedirect(route('applications.index'));

        $app->refresh();
        $this->assertSame('withdrawn', $app->status);

        $event = $app->events()->where('type', 'status_change')->latest('occurred_at')->first();
        $this->assertNotNull($event);
        $this->assertEquals('applied', $event->payload['from']);
        $this->assertEquals('withdrawn', $event->payload['to']);
    }
}
