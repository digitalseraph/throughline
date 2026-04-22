<?php

namespace Tests\Feature\Admin;

use App\Models\AdminActivityLog;
use App\Models\Application;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_with_search_filters_by_email(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['email' => 'alice@example.com', 'name' => 'Alice']);
        User::factory()->create(['email' => 'bob@example.com', 'name' => 'Bob']);

        $response = $this->actingAs($admin)->get(route('admin.users.index', ['q' => 'alice']));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertCount(1, $props['users']['data']);
        $this->assertSame('alice@example.com', $props['users']['data'][0]['email']);
    }

    public function test_detail_includes_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        Resume::factory()->for($user)->count(2)->create();
        Application::factory()->for($user)->count(3)->create();

        $response = $this->actingAs($admin)->get(route('admin.users.show', $user));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertSame(2, $props['user']['resume_count']);
        $this->assertSame(3, $props['user']['application_count']);
    }

    public function test_update_role_writes_audit_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'user']);

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $target), ['role' => 'admin'])
            ->assertRedirect();

        $this->assertSame('admin', $target->fresh()->role);
        $this->assertSame(1, AdminActivityLog::where('action', 'user.role_changed')->count());
    }

    public function test_deactivate_sets_column_and_audits(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'user']);

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $target), ['deactivate' => true])
            ->assertRedirect();

        $this->assertNotNull($target->fresh()->deactivated_at);
        $this->assertSame(1, AdminActivityLog::where('action', 'user.deactivated')->count());
    }

    public function test_admin_cannot_modify_own_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $admin), ['deactivate' => true])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $admin), ['role' => 'user'])
            ->assertStatus(422);

        $this->assertNull($admin->fresh()->deactivated_at);
        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_non_admin_forbidden(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $target = User::factory()->create();

        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($user)->patch(route('admin.users.update', $target), ['role' => 'admin'])->assertForbidden();
    }
}
