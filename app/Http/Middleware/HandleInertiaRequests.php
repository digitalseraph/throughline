<?php

namespace App\Http\Middleware;

use App\Models\Application;
use App\Models\User;
use App\Services\SiteSettings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
            ],
            'reminderBadgeCount' => fn () => $request->user()
                ? Cache::remember(
                    "reminder_badge:{$request->user()->id}",
                    (int) app(SiteSettings::class)->get('nav_reminder_badge_ttl_seconds', '60'),
                    fn () => Application::where('user_id', $request->user()->id)
                        ->whereNotIn('status', Application::TERMINAL_STATUSES)
                        ->whereNotNull('next_action_at')
                        ->where('next_action_at', '<=', now())
                        ->count(),
                )
                : 0,
            'impersonation' => function () use ($request) {
                if (! $originalId = $request->session()->get('original_user_id')) {
                    return null;
                }
                $current = $request->user();
                $original = User::find($originalId);

                return [
                    'current_email' => $current?->email,
                    'original_email' => $original?->email,
                    'stop_url' => route('admin.impersonate.stop'),
                ];
            },
        ]);
    }
}
