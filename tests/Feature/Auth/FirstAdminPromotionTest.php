<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirstAdminPromotionTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_registered_user_becomes_admin(): void
    {
        $response = $this->post('/register', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        $response->assertRedirect(route('dashboard'));

        $user = User::where('email', 'alice@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->isAdmin());
    }

    public function test_second_registered_user_stays_as_regular_user(): void
    {
        User::factory()->create(['role' => 'admin']);

        $this->post('/register', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        $user = User::where('email', 'bob@example.com')->first();
        $this->assertSame('user', $user->role);
        $this->assertFalse($user->isAdmin());
    }
}
