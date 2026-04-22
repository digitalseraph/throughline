<?php

namespace Tests\Feature\Admin;

use App\Models\CanonicalJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_overview_cards_with_aggregates(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(3)->create(['role' => 'user']);
        CanonicalJob::factory()->count(5)->create();

        $response = $this->actingAs($admin)->get(route('admin.overview'));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertArrayHasKey('cards', $props);
        $this->assertGreaterThanOrEqual(4, $props['cards']['total_users']); // 3 users + 1 admin
        $this->assertSame(5, $props['cards']['total_jobs']);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->actingAs($user)->get(route('admin.overview'))->assertForbidden();
    }

    public function test_unauthenticated_redirects_to_login(): void
    {
        $this->get(route('admin.overview'))->assertRedirect(route('login'));
    }
}
