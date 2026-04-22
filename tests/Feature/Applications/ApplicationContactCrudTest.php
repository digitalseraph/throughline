<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\ApplicationContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationContactCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_contact_writes_event(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('applications.contacts.store', $app), [
                'name' => 'Jane Recruiter',
                'role' => 'recruiter',
                'email' => 'jane@example.com',
            ])
            ->assertRedirect();

        $this->assertSame(1, $app->contacts()->count());
        $this->assertSame(1, $app->events()->where('type', 'contact_added')->count());
    }

    public function test_update_contact(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();
        $contact = ApplicationContact::factory()->for($app)->create(['name' => 'Old name', 'role' => 'recruiter']);

        $this->actingAs($user)
            ->patch(route('applications.contacts.update', [$app, $contact]), [
                'name' => 'New name', 'role' => 'hiring_manager',
            ])
            ->assertRedirect();

        $this->assertSame('New name', $contact->fresh()->name);
        $this->assertSame('hiring_manager', $contact->fresh()->role);
    }

    public function test_delete_contact(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();
        $contact = ApplicationContact::factory()->for($app)->create();

        $this->actingAs($user)->delete(route('applications.contacts.destroy', [$app, $contact]))->assertRedirect();

        $this->assertNull($contact->fresh());
    }

    public function test_stranger_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $app = Application::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->post(route('applications.contacts.store', $app), ['name' => 'X', 'role' => 'recruiter'])
            ->assertForbidden();
    }

    public function test_invalid_role_rejected(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('applications.contacts.store', $app), ['name' => 'X', 'role' => 'bogus'])
            ->assertSessionHasErrors('role');
    }
}
