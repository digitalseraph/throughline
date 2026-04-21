<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;

class SiteSettings
{
    private const CACHE_KEY = 'site_settings:all';

    private const CACHE_TTL = 3600;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value, ?int $updatedBy = null): void
    {
        SiteSetting::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $updatedBy],
        );
        $this->invalidate();
    }

    public function all(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL,
            fn () => SiteSetting::all()->pluck('value', 'key')->toArray(),
        );
    }

    public function invalidate(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
