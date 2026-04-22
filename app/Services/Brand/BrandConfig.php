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

    public function wordmarkUrl(): string
    {
        $override = $this->settings->get('brand_wordmark_url_override', '');
        if ($override) {
            return $override;
        }

        return asset(config('brand.wordmark_path', '/brand/wordmark.svg'));
    }

    public function faviconUrl(): string
    {
        $override = $this->settings->get('brand_favicon_url_override', '');
        if ($override) {
            return $override;
        }

        return asset(config('brand.favicon_path', '/favicon.ico'));
    }

    public function colorPrimary(): string
    {
        return $this->settings->get('brand_color_primary_override', '') ?: config('brand.colors.ink', '#1a1410');
    }

    public function colorOxblood(): string
    {
        return $this->settings->get('brand_color_oxblood_override', '') ?: config('brand.colors.oxblood', '#a83a1a');
    }

    public function fontSans(): string
    {
        return $this->settings->get('brand_font_sans_override', '') ?: config('brand.fonts.sans', "'Inter', system-ui, sans-serif");
    }

    public function clearCache(): void
    {
        $this->settings->invalidate();
    }

    private function assetPath(string $key): string
    {
        $overrides = (array) $this->settings->get('brand_asset_overrides', []);

        return (string) ($overrides[$key] ?? config("brand.assets.{$key}"));
    }
}
