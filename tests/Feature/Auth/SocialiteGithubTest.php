<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class SocialiteGithubTest extends TestCase
{
    use RefreshDatabase;

    public function test_callback_creates_user_from_github(): void
    {
        $socialUser = new SocialiteUser;
        $socialUser->id = 'gh-42';
        $socialUser->email = 'dev@example.com';
        $socialUser->name = 'Dev User';
        $socialUser->nickname = 'devuser';
        $socialUser->token = 'tok';
        $socialUser->refreshToken = null;

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with('github')->andReturn($provider);

        $response = $this->get('/auth/github/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', ['email' => 'dev@example.com']);
        $this->assertDatabaseHas('social_accounts', ['provider' => 'github', 'provider_user_id' => 'gh-42']);
    }

    public function test_unknown_provider_returns_404(): void
    {
        $this->get('/auth/facebook/redirect')->assertNotFound();
        $this->get('/auth/facebook/callback')->assertNotFound();
    }

    public function test_user_can_have_both_providers_linked(): void
    {
        $user = User::factory()->create(['email' => 'multi@example.com']);

        foreach (['google' => 'g-1', 'github' => 'gh-1'] as $provider => $providerId) {
            $socialUser = new SocialiteUser;
            $socialUser->id = $providerId;
            $socialUser->email = 'multi@example.com';
            $socialUser->name = 'Multi';
            $socialUser->token = 'tok';
            $socialUser->refreshToken = null;

            $providerMock = Mockery::mock(Provider::class);
            $providerMock->shouldReceive('user')->andReturn($socialUser);
            Socialite::shouldReceive('driver')->with($provider)->andReturn($providerMock);

            $this->get("/auth/{$provider}/callback");
        }

        $this->assertSame(1, User::count());
        $this->assertSame(2, $user->fresh()->socialAccounts()->count());
    }
}
