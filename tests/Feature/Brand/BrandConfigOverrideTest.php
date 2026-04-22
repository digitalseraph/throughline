<?php

namespace Tests\Feature\Brand;

use App\Models\SiteSetting;
use App\Services\Brand\BrandConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandConfigOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_override_wins_when_set(): void
    {
        SiteSetting::create(['key' => 'brand_wordmark_url_override', 'value' => 'https://cdn.example.com/wordmark.svg', 'type' => 'string', 'description' => '']);

        $this->assertSame('https://cdn.example.com/wordmark.svg', app(BrandConfig::class)->wordmarkUrl());
    }

    public function test_default_used_when_override_empty(): void
    {
        SiteSetting::create(['key' => 'brand_wordmark_url_override', 'value' => '', 'type' => 'string', 'description' => '']);

        $this->assertStringContainsString('wordmark', app(BrandConfig::class)->wordmarkUrl());
    }

    public function test_color_override_falls_back_cleanly(): void
    {
        $this->assertSame('#a83a1a', app(BrandConfig::class)->colorOxblood());

        SiteSetting::updateOrCreate(['key' => 'brand_color_oxblood_override'], ['value' => '#ff0000', 'type' => 'string', 'description' => '']);
        app(BrandConfig::class)->clearCache();

        $this->assertSame('#ff0000', app(BrandConfig::class)->colorOxblood());
    }
}
