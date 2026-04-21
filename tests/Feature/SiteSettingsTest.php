<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_default_when_key_missing(): void
    {
        $settings = app(SiteSettings::class);

        $this->assertSame('fallback', $settings->get('unknown', 'fallback'));
    }

    public function test_returns_db_value_when_present(): void
    {
        SiteSetting::create(['key' => 'site_name', 'value' => 'Custom', 'type' => 'string']);

        $settings = app(SiteSettings::class);

        $this->assertSame('Custom', $settings->get('site_name'));
    }

    public function test_set_writes_to_db_and_invalidates_cache(): void
    {
        $settings = app(SiteSettings::class);
        $settings->get('site_name');
        $this->assertTrue(Cache::has('site_settings:all'));

        $settings->set('site_name', 'Updated');

        $this->assertFalse(Cache::has('site_settings:all'));
        $this->assertSame('Updated', $settings->get('site_name'));
        $this->assertDatabaseHas('site_settings', ['key' => 'site_name']);
    }

    public function test_subsequent_reads_hit_cache(): void
    {
        SiteSetting::create(['key' => 'site_name', 'value' => 'First', 'type' => 'string']);

        $settings = app(SiteSettings::class);
        $this->assertSame('First', $settings->get('site_name'));

        SiteSetting::where('key', 'site_name')->update(['value' => json_encode('Sneaky')]);

        $this->assertSame('First', $settings->get('site_name'), 'should be served from cache');
    }
}
