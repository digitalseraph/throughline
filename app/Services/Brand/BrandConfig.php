<?php

namespace App\Services\Brand;

use App\Services\SiteSettings;

class BrandConfig
{
    public function __construct(private SiteSettings $settings) {}

    public function name(): string
    {
        return (string) $this->settings->get('site_name', config('brand.name'));
    }

    public function tagline(): string
    {
        return (string) $this->settings->get('site_tagline', config('brand.tagline'));
    }

    public function mailFromName(): string
    {
        return config('mail.from.name') ?: $this->name();
    }

    public function wordmarkPath(): string
    {
        return $this->assetPath('wordmark');
    }

    public function waypointPath(): string
    {
        return $this->assetPath('waypoint');
    }

    public function faviconPath(): string
    {
        return $this->assetPath('favicon');
    }

    private function assetPath(string $key): string
    {
        $overrides = (array) $this->settings->get('brand_asset_overrides', []);

        return (string) ($overrides[$key] ?? config("brand.assets.{$key}"));
    }
}
