<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SocialiteController extends Controller
{
    private const ALLOWED_PROVIDERS = ['google', 'github'];

    public function redirect(string $provider): Response
    {
        $this->guardProvider($provider);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->guardProvider($provider);

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (Throwable) {
            return redirect()->route('login')->with('error', 'Sign-in failed. Please try again.');
        }

        if (! $socialUser->getEmail()) {
            return redirect()->route('login')->with('error', 'Provider did not return an email address.');
        }

        // Refuse new account creation when signups are closed. Existing accounts
        // (matched by email or by an existing SocialAccount row) still get in.
        $signupsEnabled = (bool) app(SiteSettings::class)->get('signups_enabled', true);
        if (! $signupsEnabled) {
            $existingAccount = SocialAccount::where('provider', $provider)
                ->where('provider_user_id', (string) $socialUser->getId())
                ->exists();
            $existingByEmail = User::where('email', $socialUser->getEmail())->exists();
            if (! $existingAccount && ! $existingByEmail) {
                return redirect()->route('login')->with('error', 'New registrations are currently closed.');
            }
        }

        $user = DB::transaction(function () use ($provider, $socialUser) {
            $account = SocialAccount::where('provider', $provider)
                ->where('provider_user_id', (string) $socialUser->getId())
                ->first();

            if ($account) {
                $account->update([
                    'access_token' => $socialUser->token,
                    'refresh_token' => $socialUser->refreshToken,
                ]);

                $account->user->update(['last_login_at' => now()]);

                return $account->user;
            }

            $existingUser = User::where('email', $socialUser->getEmail())->first();

            if ($existingUser) {
                $existingUser->update(['last_login_at' => now()]);
                $user = $existingUser;
            } else {
                $user = User::create([
                    'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? $socialUser->getEmail(),
                    'email' => $socialUser->getEmail(),
                    'email_verified_at' => now(),
                    'password' => null,
                    'role' => User::count() === 0 ? 'admin' : 'user',
                    'last_login_at' => now(),
                ]);
            }

            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_user_id' => (string) $socialUser->getId(),
                'access_token' => $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
            ]);

            return $user;
        });

        $request->session()->regenerate();

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }

    private function guardProvider(string $provider): void
    {
        abort_unless(in_array($provider, self::ALLOWED_PROVIDERS, true), 404);
    }
}
