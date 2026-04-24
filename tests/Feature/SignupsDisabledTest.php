<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignupsDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteSetting::firstOrCreate(['key' => 'maintenance_mode'], ['value' => false, 'type' => 'bool', 'description' => '']);
        SiteSetting::firstOrCreate(['key' => 'signups_enabled'], ['value' => true, 'type' => 'bool', 'description' => '']);
        SiteSetting::firstOrCreate(['key' => 'site_name'], ['value' => 'Throughline', 'type' => 'string', 'description' => '']);
    }

    public function test_register_page_loads_when_signups_enabled(): void
    {
        $this->get(route('register'))->assertOk();
    }

    public function test_register_get_redirects_to_login_with_error_when_disabled(): void
    {
        $this->setSignups(false);

        $response = $this->get('/register');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error', 'New registrations are currently closed.');
    }

    public function test_register_post_redirects_to_login_with_error_when_disabled(): void
    {
        $this->setSignups(false);

        $response = $this->post('/register', [
            'name' => 'Test',
            'email' => 'new@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_existing_user_can_still_log_in_when_signups_disabled(): void
    {
        $this->setSignups(false);
        $user = User::factory()->create([
            'email' => 'existing@example.com',
            'password' => bcrypt('secret'),
        ]);

        $response = $this->post(route('login'), [
            'email' => 'existing@example.com',
            'password' => 'secret',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    protected function setSignups(bool $on): void
    {
        SiteSetting::where('key', 'signups_enabled')->update(['value' => $on ? 'true' : 'false']);
        app(\App\Services\SiteSettings::class)->invalidate();
    }
}
