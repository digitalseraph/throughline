<?php

namespace Tests\Feature\Brand;

use App\Services\Brand\BrandConfig;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_falls_back_to_config_default(): void
    {
        config()->set('brand.name', 'Throughline');

        $brand = app(BrandConfig::class);

        $this->assertSame('Throughline', $brand->name());
    }

    public function test_name_prefers_site_settings_override(): void
    {
        config()->set('brand.name', 'Throughline');

        $settings = $this->createMock(SiteSettings::class);
        $settings->method('get')->willReturnCallback(
            fn (string $key, $default = null) => $key === 'site_name' ? 'Custom Brand' : $default,
        );
        $this->app->instance(SiteSettings::class, $settings);

        $brand = app(BrandConfig::class);

        $this->assertSame('Custom Brand', $brand->name());
    }

    public function test_tagline_and_wordmark_fall_back_to_config(): void
    {
        config()->set('brand.tagline', 'Every career has a thread.');
        config()->set('brand.assets.wordmark', '/brand/wordmark.svg');

        $brand = app(BrandConfig::class);

        $this->assertSame('Every career has a thread.', $brand->tagline());
        $this->assertSame('/brand/wordmark.svg', $brand->wordmarkPath());
    }
}
