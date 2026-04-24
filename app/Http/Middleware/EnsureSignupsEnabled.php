<?php

namespace App\Http\Middleware;

use App\Services\SiteSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSignupsEnabled
{
    public function __construct(private SiteSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) $this->settings->get('signups_enabled', true)) {
            return $next($request);
        }

        $message = 'New registrations are currently closed.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return redirect()->route('login')->with('error', $message);
    }
}
