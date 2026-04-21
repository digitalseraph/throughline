<?php

namespace App\Providers;

use App\Services\Ai\AiClient;
use App\Services\Brand\BrandConfig;
use App\Services\SiteSettings;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SiteSettings::class);
        $this->app->singleton(BrandConfig::class);
        $this->app->singleton(AiClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
