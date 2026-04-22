<?php

namespace Tests\Feature\Admin;

use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuditLogViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_paginates_and_filters_by_action(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        AdminActivityLog::factory()->count(3)->create([
            'admin_user_id' => $admin->id,
            'action' => 'user.role_changed',
        ]);

        AdminActivityLog::factory()->count(2)->create([
            'admin_user_id' => $admin->id,
            'action' => 'user.deactivated',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.audit.index', ['action' => 'role_changed']));

        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertCount(3, $props['logs']['data']);
    }

    public function test_non_admin_forbidden(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->get(route('admin.audit.index'))
            ->assertForbidden();
    }
}
