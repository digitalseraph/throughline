<?php

namespace App\Http\Middleware;

use App\Services\Brand\BrandConfig;
use App\Services\SiteSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSiteOpen
{
    public function __construct(
        private SiteSettings $settings,
        private BrandConfig $brand,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) $this->settings->get('maintenance_mode', false)) {
            return $next($request);
        }

        // Admins bypass so they can toggle maintenance off and continue working.
        if ($request->user()?->isAdmin()) {
            return $next($request);
        }

        // Login + logout stay open so admins can sign in to lift the lockout.
        if ($this->isAuthBypassRoute($request)) {
            return $next($request);
        }

        return response()->view('maintenance', [
            'siteName' => $this->brand->name(),
            'tagline' => $this->brand->tagline(),
        ], 503);
    }

    protected function isAuthBypassRoute(Request $request): bool
    {
        $name = $request->route()?->getName();

        if (in_array($name, ['login', 'logout', 'auth.social.redirect', 'auth.social.callback'], true)) {
            return true;
        }

        // Health check + Cloudflare beacons should never 503.
        if ($request->is('up') || $request->is('cdn-cgi/*')) {
            return true;
        }

        return false;
    }
}
