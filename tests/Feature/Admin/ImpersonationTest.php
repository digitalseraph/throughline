<?php

namespace Tests\Feature\Admin;

use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_start_impersonation_of_non_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'user']);

        $this->actingAs($admin)
            ->post(route('admin.impersonate.start', $target))
            ->assertRedirect();

        $this->assertSame($target->id, auth()->id());
        $this->assertSame($admin->id, session('original_user_id'));
        $this->assertSame(1, AdminActivityLog::where('action', 'user.impersonation_started')->count());
    }

    public function test_impersonation_of_admin_is_forbidden(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('admin.impersonate.start', $otherAdmin))
            ->assertForbidden();

        $this->assertSame(0, AdminActivityLog::where('action', 'user.impersonation_started')->count());
    }

    public function test_nested_impersonation_is_forbidden(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $targetA = User::factory()->create(['role' => 'user']);
        $targetB = User::factory()->create(['role' => 'user']);

        $this->actingAs($admin)->post(route('admin.impersonate.start', $targetA));

        // We're now logged in as targetA. Try to start a nested impersonation.
        // The route is gated by EnsureAdmin which requires admin role — so targetA will get 403.
        $this->post(route('admin.impersonate.start', $targetB))->assertForbidden();
    }

    public function test_stop_returns_to_original_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'user']);

        $this->actingAs($admin)->post(route('admin.impersonate.start', $target));
        $this->post(route('admin.impersonate.stop'))->assertRedirect();

        $this->assertSame($admin->id, auth()->id());
        $this->assertNull(session('original_user_id'));
        $this->assertSame(1, AdminActivityLog::where('action', 'user.impersonation_stopped')->count());
    }
}
