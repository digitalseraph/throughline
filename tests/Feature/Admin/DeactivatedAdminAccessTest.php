<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeactivatedAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivated_admin_cannot_access_admin_routes(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'deactivated_at' => now()]);

        $this->actingAs($admin)->get(route('admin.overview'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_active_admin_can_access_admin_routes(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'deactivated_at' => null]);

        $this->actingAs($admin)->get(route('admin.overview'))->assertOk();
    }
}
