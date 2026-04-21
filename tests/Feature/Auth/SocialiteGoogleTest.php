<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class SocialiteGoogleTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirect_route_hands_off_to_google(): void
    {
        $response = $this->get('/auth/google/redirect');

        $response->assertStatus(302);
        $this->assertStringContainsString('google', $response->headers->get('Location'));
    }

    public function test_callback_creates_user_and_social_account_and_promotes_first_user_to_admin(): void
    {
        $socialUser = $this->fakeSocialiteUser([
            'id' => 'g-123',
            'email' => 'alice@example.com',
            'name' => 'Alice Anderson',
            'token' => 'acc-tok',
            'refreshToken' => 'ref-tok',
        ]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', ['email' => 'alice@example.com', 'role' => 'admin']);
        $this->assertDatabaseHas('social_accounts', ['provider' => 'google', 'provider_user_id' => 'g-123']);
    }

    public function test_callback_links_existing_user_by_email(): void
    {
        $existing = User::factory()->create(['email' => 'alice@example.com', 'role' => 'user']);

        $socialUser = $this->fakeSocialiteUser([
            'id' => 'g-999',
            'email' => 'alice@example.com',
            'name' => 'Alice A.',
        ]);
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertSame(1, User::count());
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $existing->id,
            'provider' => 'google',
            'provider_user_id' => 'g-999',
        ]);
    }

    public function test_callback_handles_provider_failure_gracefully(): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andThrow(new \RuntimeException('oauth boom'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertSame(0, User::count());
    }

    public function test_second_oauth_created_user_does_not_become_admin(): void
    {
        User::factory()->create(['role' => 'admin']);

        $socialUser = $this->fakeSocialiteUser([
            'id' => 'g-second',
            'email' => 'second@example.com',
            'name' => 'Second User',
        ]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google/callback');

        $this->assertDatabaseHas('users', [
            'email' => 'second@example.com',
            'role' => 'user',
        ]);
    }

    private function fakeSocialiteUser(array $attrs): SocialiteUser
    {
        $user = new SocialiteUser;
        $user->id = $attrs['id'];
        $user->email = $attrs['email'];
        $user->name = $attrs['name'] ?? null;
        $user->token = $attrs['token'] ?? 'token';
        $user->refreshToken = $attrs['refreshToken'] ?? null;

        return $user;
    }
}
