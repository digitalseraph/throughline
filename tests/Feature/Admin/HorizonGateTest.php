<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HorizonGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_horizon(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/horizon')->assertSuccessful();
    }

    public function test_non_admin_cannot_view_horizon(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $response = $this->actingAs($user)->get('/horizon');
        $this->assertTrue(in_array($response->status(), [403, 404]), "Non-admin got status {$response->status()}");
    }
}
