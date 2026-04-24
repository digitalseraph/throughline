<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteSetting::firstOrCreate(['key' => 'maintenance_mode'], ['value' => false, 'type' => 'bool', 'description' => '']);
        SiteSetting::firstOrCreate(['key' => 'signups_enabled'], ['value' => true, 'type' => 'bool', 'description' => '']);
        SiteSetting::firstOrCreate(['key' => 'site_name'], ['value' => 'Throughline', 'type' => 'string', 'description' => '']);
    }

    public function test_homepage_loads_normally_when_maintenance_off(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_anonymous_visitor_gets_503_maintenance_page_when_on(): void
    {
        $this->setMaintenance(true);

        $response = $this->get('/');

        $response->assertStatus(503);
        $response->assertSee("We're tuning things up.", false);
    }

    public function test_regular_authenticated_user_also_gets_503(): void
    {
        $this->setMaintenance(true);
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)->get('/dashboard')->assertStatus(503);
    }

    public function test_admin_bypasses_maintenance_mode(): void
    {
        $this->setMaintenance(true);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_login_route_stays_open_during_maintenance(): void
    {
        $this->setMaintenance(true);

        $this->get(route('login'))->assertOk();
    }

    public function test_health_check_stays_open_during_maintenance(): void
    {
        $this->setMaintenance(true);

        // /up returns 200 with the Laravel health check page
        $this->get('/up')->assertOk();
    }

    protected function setMaintenance(bool $on): void
    {
        SiteSetting::where('key', 'maintenance_mode')->update(['value' => $on ? 'true' : 'false']);
        app(\App\Services\SiteSettings::class)->invalidate();
    }
}
