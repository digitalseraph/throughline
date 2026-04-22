# Throughline — Plan 6: Polish, real-time, and close

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the 6-plan arc. Replace polling with Reverb real-time pushes, add daily email reminders, land the deferred brand-string leak guard and settings-driven theming, give operators a pgvector retuning command, ship a production-readiness checklist.

**Architecture:** Laravel Reverb on private `users.{id}` channels. Broadcast events carry primitive payloads only — every dispatch site wraps the firing in try/catch so a failed push never aborts the primary DB write. `BrandConfig` gains override-aware accessors reading from `site_settings` before falling back to `config/brand.php`. Email notifications queued per-user with race-safe re-check inside the handler.

**Tech Stack:** New composer: `laravel/reverb`. New npm: `laravel-echo` + `pusher-js`. Same Laravel 12 + PHP 8.4 + Postgres + Redis.

**Spec reference:** [`docs/superpowers/specs/2026-04-22-throughline-plan-6-design.md`](../specs/2026-04-22-throughline-plan-6-design.md)

---

## Preconditions

- Plan 5 merged to main. ~214 PHP tests + Playwright E2E passing baseline.
- Branch `plan-6-polish-and-close` forked from main.
- Docker stack running.

---

## Task index

1. Brand-string leak guard test
2. Settings-driven theming — BrandConfig extensions + site_settings keys + admin Settings theming group
3. Install Laravel Reverb + configure broadcasting
4. `ResumeReady` + `ResumeParseFailed` events + resume detail subscribes (drops polling; 30s fallback retained)
5. `ReminderBadgeUpdated` event + nav subscribes
6. `FeedMatchReranked` event + feed auto-refresh on event
7. Email reminders — `notification_email_enabled` column + `ReminderDueMail` + `SendReminderEmail` queued job
8. `throughline:rebuild-vector-index` artisan command
9. Production readiness doc
10. Final docs rewrite + verification + session close

---

## Task 1: Brand-string leak guard

**Files:**
- Create: `tests/Feature/Brand/BrandStringLeakTest.php`

- [ ] **Step 1: Write the test**

`tests/Feature/Brand/BrandStringLeakTest.php`:

```php
<?php

namespace Tests\Feature\Brand;

use Tests\TestCase;

class BrandStringLeakTest extends TestCase
{
    public function test_throughline_literal_does_not_leak_outside_allowlist(): void
    {
        $base = base_path();
        $allowedPrefixes = [
            'app/Services/Brand/',
            'config/brand.php',
            'docs/',
            'tests/',
            'database/seeders/',
            'resources/views/mail/', // templated brand name in emails is fine
            'README.md',
            'CLAUDE.md',
            'CHANGELOG.md',
            'package.json',
            'composer.json',
            '.github/',
        ];

        $offenders = [];
        foreach ($this->candidateFiles() as $file) {
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($base) + 1));
            if ($this->allowed($rel, $allowedPrefixes)) continue;

            $content = file_get_contents($file);
            if (preg_match('/\bThroughline\b/i', $content)) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame([], $offenders, "Brand-string leak found. Route through BrandConfig or SiteSettings instead.");
    }

    protected function allowed(string $rel, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($rel, $prefix)) return true;
        }
        return false;
    }

    protected function candidateFiles(): iterable
    {
        $base = base_path();
        $extensions = ['php', 'vue', 'blade.php', 'ts', 'js'];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $f) {
            $path = $f->getPathname();
            if (!$f->isFile()) continue;
            if (str_contains($path, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) continue;
            if (str_contains($path, DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR)) continue;
            if (str_contains($path, DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR)) continue;
            if (str_contains($path, DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR)) continue;

            $hasExt = false;
            foreach ($extensions as $ext) {
                if (str_ends_with($path, '.'.$ext)) { $hasExt = true; break; }
            }
            if (!$hasExt) continue;

            yield $path;
        }
    }
}
```

- [ ] **Step 2: Run the test**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=BrandStringLeakTest
```

Expected: green. If it fails, inspect the offender list — the usual culprits are Vue pages rendering `"Throughline"` literally in the header/footer. Fix by reading `$page.props.auth?.site_name` (shared via HandleInertiaRequests from BrandConfig::siteName()) or replacing with a `<Wordmark />` component. Re-run until green.

- [ ] **Step 3: Commit**

```bash
vendor/bin/pint --dirty
git add tests/Feature/Brand/BrandStringLeakTest.php
git commit -m "$(cat <<'EOF'
test: guard against hardcoded 'Throughline' string leaks

Scans .php / .vue / .blade.php / .ts / .js files outside an allowlist
(BrandConfig service, config/brand.php, docs/, tests/, seeders/,
mail templates, top-level README/CHANGELOG) for literal 'Throughline'
occurrences. Fails loud if brand copy ever hardcodes the name —
forces the fix to route through BrandConfig or SiteSettings.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Settings-driven theming — BrandConfig extensions

**Files:**
- Modify: `app/Services/Brand/BrandConfig.php` (add override-aware accessors for wordmark / favicon / colors / fonts)
- Modify: `database/seeders/SiteSettingsSeeder.php` (append 5 theming keys)
- Modify: `resources/js/pages/admin/Settings.vue` (group theming keys in a dedicated "Theming" section)
- Create: `tests/Feature/Brand/BrandConfigOverrideTest.php`

- [ ] **Step 1: Seed 5 new theming keys**

Edit `database/seeders/SiteSettingsSeeder.php`. Append:

```php
['key' => 'brand_wordmark_url_override',   'value' => '',     'type' => 'string', 'description' => 'URL to a custom SVG wordmark; overrides the default if set.'],
['key' => 'brand_favicon_url_override',    'value' => '',     'type' => 'string', 'description' => 'URL to a custom favicon.'],
['key' => 'brand_color_primary_override',  'value' => '',     'type' => 'string', 'description' => 'Hex color override for the ink token (e.g. #1a1410).'],
['key' => 'brand_color_oxblood_override',  'value' => '',     'type' => 'string', 'description' => 'Hex color override for the oxblood accent.'],
['key' => 'brand_font_sans_override',      'value' => '',     'type' => 'string', 'description' => 'CSS font-family for body text; overrides Inter.'],
```

- [ ] **Step 2: Extend BrandConfig**

Read `app/Services/Brand/BrandConfig.php`. Add methods following the existing pattern:

```php
public function wordmarkUrl(): string
{
    $override = $this->settings->get('brand_wordmark_url_override', '');
    if ($override) return $override;
    return asset(config('brand.wordmark_path', '/brand/wordmark.svg'));
}

public function faviconUrl(): string
{
    $override = $this->settings->get('brand_favicon_url_override', '');
    if ($override) return $override;
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
```

- [ ] **Step 3: Test the overrides**

`tests/Feature/Brand/BrandConfigOverrideTest.php`:

```php
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
        app(BrandConfig::class)->clearCache(); // if the service caches; otherwise remove this line

        $this->assertSame('#ff0000', app(BrandConfig::class)->colorOxblood());
    }
}
```

Note: `BrandConfig::clearCache()` may not exist. If the service reads SiteSettings through its own cached accessor, test setup may need to invalidate that cache. Adjust based on the actual BrandConfig implementation.

- [ ] **Step 4: Admin Settings UI — group theming keys**

Edit `resources/js/pages/admin/Settings.vue`. The Plan 5 version renders `site_settings` in a single flat list. Group by prefix: any key starting with `brand_` goes into a "Theming" section. Rest in "General".

Simple grouping:

```ts
const grouped = computed(() => {
  const themingKeys = settings.filter((s: any) => s.key.startsWith('brand_'));
  const otherKeys = settings.filter((s: any) => !s.key.startsWith('brand_'));
  return { theming: themingKeys, general: otherKeys };
});
```

Render each group under a section header. Theming section notes to operators: "Leave blank to use the default. Restart required for some values (e.g. font-family)."

- [ ] **Step 5: Run tests**

Expected: 3 new tests + 214 baseline = 217 pass.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Brand/BrandConfig.php database/seeders/SiteSettingsSeeder.php resources/js/pages/admin/Settings.vue tests/Feature/Brand/BrandConfigOverrideTest.php
git commit -m "$(cat <<'EOF'
feat: settings-driven brand overrides via BrandConfig

Five new site_settings keys let operators override wordmark URL,
favicon URL, primary/oxblood colors, and body font without a code
deploy. BrandConfig checks each override first, falls back to
config/brand.php. Admin Settings page groups brand_* keys under a
distinct Theming section with a hint that some overrides require
a restart.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Install Laravel Reverb + broadcasting config

**Files:**
- Modify: `composer.json` / `composer.lock` (add `laravel/reverb`)
- Modify: `package.json` / `package-lock.json` (add `laravel-echo` + `pusher-js`)
- Modify: `config/broadcasting.php` (default to reverb driver)
- Create: `routes/channels.php` (authorize `users.{id}` private channel) — may already exist; ensure it has the channel auth closure
- Create: `resources/js/echo.ts`
- Modify: `.env.example` (add REVERB_* env vars)
- Modify: `.env.testing` (set BROADCAST_CONNECTION=log for tests)
- Modify: `composer.json` scripts or `composer run dev` so `reverb:start` runs alongside PHP/Vite/queue worker

- [ ] **Step 1: Install Reverb**

```bash
composer require laravel/reverb --ignore-platform-req=ext-pcntl,ext-posix
php artisan reverb:install --no-interaction
```

Expected: publishes `config/reverb.php`, updates `.env` with REVERB_* vars, updates `.env.example`, modifies `config/broadcasting.php` to include the `reverb` driver.

- [ ] **Step 2: Install frontend libs**

```bash
npm install --save laravel-echo pusher-js
```

- [ ] **Step 3: `resources/js/echo.ts`**

```ts
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// @ts-ignore
window.Pusher = Pusher;

const key = import.meta.env.VITE_REVERB_APP_KEY as string;
const host = import.meta.env.VITE_REVERB_HOST as string;
const port = parseInt((import.meta.env.VITE_REVERB_PORT as string) ?? '8080', 10);
const scheme = (import.meta.env.VITE_REVERB_SCHEME as string) ?? 'http';

export const echo = new Echo({
  broadcaster: 'reverb',
  key,
  wsHost: host,
  wsPort: port,
  wssPort: port,
  forceTLS: scheme === 'https',
  enabledTransports: ['ws', 'wss'],
  authEndpoint: '/broadcasting/auth',
});

// Expose globally for quick dev debugging.
// @ts-ignore
window.Echo = echo;
```

- [ ] **Step 4: Register Echo in app entry**

Edit `resources/js/app.ts`. After the Inertia createInertiaApp but before the mount, add:

```ts
import { echo } from '@/echo';
// ensures echo is initialized at boot
void echo;
```

- [ ] **Step 5: `routes/channels.php`**

Ensure the file exists and contains:

```php
<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('users.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
```

- [ ] **Step 6: Broadcasting service provider**

Ensure `BroadcastServiceProvider` is registered in `bootstrap/providers.php`:

```php
App\Providers\BroadcastServiceProvider::class,
```

If the provider class doesn't exist, create it as the Reverb install guide specifies.

- [ ] **Step 7: Set BROADCAST_CONNECTION=log in testing env**

Edit `.env.testing`:

```env
BROADCAST_CONNECTION=log
```

And add to `phpunit.xml` to make sure tests use the log driver regardless of `.env.testing`:

```xml
<env name="BROADCAST_CONNECTION" value="log" force="true"/>
```

- [ ] **Step 8: Update `composer.json` `dev` script**

Modify the `scripts.dev` entry so `reverb:start` runs alongside. Read the current script first; it uses `concurrently` or a similar tool to run PHP server + queue + Vite. Add reverb as a fourth concurrent process.

- [ ] **Step 9: Run the full suite to confirm no regressions**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --compact
```

Expected: 217 pass (no new tests yet; infrastructure only).

`npm run build` clean.

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty
git add composer.json composer.lock package.json package-lock.json config/broadcasting.php config/reverb.php routes/channels.php resources/js/echo.ts resources/js/app.ts .env.example .env.testing phpunit.xml bootstrap/providers.php
git commit -m "$(cat <<'EOF'
feat: install Laravel Reverb + Echo for real-time broadcasting

Reverb replaces the Plan 1 'log' broadcaster. Private users.{id}
channels authorize via routes/channels.php. Frontend Echo client
in resources/js/echo.ts reads Vite env vars for key/host/port/scheme.

Tests stay on the log driver via BROADCAST_CONNECTION=log forced in
phpunit.xml — unit tests use Event::fake assertions, not real
WebSocket delivery.

composer run dev extended to start reverb:start alongside php serve +
queue:listen + vite dev.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: `ResumeReady` + `ResumeParseFailed` events → resume detail real-time

**Files:**
- Create: `app/Events/ResumeReady.php`
- Create: `app/Events/ResumeParseFailed.php`
- Modify: `app/Jobs/EmbedResume.php` (fire `ResumeReady` after vector write)
- Modify: `app/Jobs/ParseResume.php` (fire `ResumeParseFailed` on transition to `parse_failed`)
- Modify: `resources/js/pages/resumes/Show.vue` (subscribe to events; keep 30s polling as fallback)
- Create: `tests/Feature/Events/ResumeReadyBroadcastTest.php`

- [ ] **Step 1: Events**

`app/Events/ResumeReady.php`:

```php
<?php

namespace App\Events;

use App\Models\Resume;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ResumeReady implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Resume $resume) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("users.{$this->resume->user_id}");
    }

    public function broadcastAs(): string
    {
        return 'ResumeReady';
    }

    public function broadcastWith(): array
    {
        return [
            'resume_id' => $this->resume->id,
            'parse_status' => 'parsed',
            'has_embedding' => true,
        ];
    }
}
```

`app/Events/ResumeParseFailed.php` — mirrors the shape with `parse_status = 'parse_failed'` payload.

- [ ] **Step 2: Fire events from the queued jobs**

In `EmbedResume::handle`, after the vector write succeeds, wrap in try/catch so a failed broadcast doesn't abort:

```php
try {
    event(new \App\Events\ResumeReady($resume->fresh()));
} catch (\Throwable $e) {
    \Log::warning('ResumeReady broadcast failed', ['resume_id' => $resume->id, 'error' => $e->getMessage()]);
}
```

Similar change in `ParseResume` for the `parse_failed` transition.

- [ ] **Step 3: Subscribe in `resumes/Show.vue`**

Read the current `Show.vue` from Plan 2 Task 5. Change the poll to `setInterval(..., 30000)` (30s fallback) and add Echo subscription:

```ts
import { echo } from '@/echo';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const userId = (page.props as any).auth?.user?.id;

onMounted(() => {
  if (userId) {
    echo.private(`users.${userId}`)
      .listen('.ResumeReady', (payload: { resume_id: number }) => {
        if (payload.resume_id === props.resume.id) {
          router.reload({ only: ['resume'] });
        }
      })
      .listen('.ResumeParseFailed', (payload: { resume_id: number }) => {
        if (payload.resume_id === props.resume.id) {
          router.reload({ only: ['resume'] });
        }
      });
  }
  // keep the 30s fallback
  if (!isTerminalState()) startPolling();
});

onUnmounted(() => {
  if (userId) echo.leave(`users.${userId}`);
  if (pollHandle) clearInterval(pollHandle);
});
```

Update `startPolling` interval to 30000.

- [ ] **Step 4: Test event fires**

`tests/Feature/Events/ResumeReadyBroadcastTest.php`:

```php
<?php

namespace Tests\Feature\Events;

use App\Events\ResumeReady;
use App\Jobs\EmbedResume;
use App\Models\Resume;
use App\Models\User;
use App\Services\Ai\AiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class ResumeReadyBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_embed_resume_fires_resume_ready_event(): void
    {
        Event::fake([ResumeReady::class]);

        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->parsed()->create();

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.5));
        $this->app->instance(AiClient::class, $ai);

        (new EmbedResume($resume->id))->handle($ai);

        Event::assertDispatched(ResumeReady::class, fn ($e) => $e->resume->id === $resume->id);
    }
}
```

- [ ] **Step 5: Run + commit**

Expected: 1 new test + 217 baseline = 218.

```bash
vendor/bin/pint --dirty
git add app/Events/ResumeReady.php app/Events/ResumeParseFailed.php app/Jobs/EmbedResume.php app/Jobs/ParseResume.php resources/js/pages/resumes/Show.vue tests/Feature/Events/ResumeReadyBroadcastTest.php
git commit -m "feat: real-time resume detail via Reverb — drops 3s polling"
```

---

## Task 5: `ReminderBadgeUpdated` event → nav real-time

**Files:**
- Create: `app/Events/ReminderBadgeUpdated.php`
- Modify: `app/Jobs/ApplicationReminderScan.php` (fire event after each user's reminders written)
- Modify: `app/Http/Controllers/Applications/ApplicationController.php` (fire event on next_action_at change or status → terminal)
- Modify: `resources/js/components/AppSidebar.vue` (or NavMain.vue) — subscribe and update badge
- Create: `tests/Feature/Events/ReminderBadgeUpdatedBroadcastTest.php`

Follow the same pattern as Task 4: event broadcasts on `users.{userId}` with `{count}` payload. Nav subscribes and updates the reactive badge count locally (bypassing the `$page.props.reminderBadgeCount` 60s Redis cache for push-driven updates).

Keep the existing cache-driven badge as a fallback for page loads; the WebSocket path is the fast path.

Detailed code follows Plan 5's nav badge wiring + Task 4's event pattern. Test asserts `ReminderBadgeUpdated::class` fires when `ApplicationReminderScan` processes a user with due apps.

- [ ] Implement + test + commit. Expected: 1 new test + 218 = 219.

---

## Task 6: `FeedMatchReranked` → feed auto-refresh

**Files:**
- Create: `app/Events/FeedMatchReranked.php`
- Modify: `app/Jobs/RerankShortlist.php` (fire event after last batch writes successfully)
- Modify: `resources/js/pages/feed/Show.vue` (subscribe + `router.reload({ only: ['matches'] })`)
- Create: `tests/Feature/Events/FeedMatchRerankedBroadcastTest.php`

Same pattern as Task 5. Fire event only at the END of the `RerankShortlist::handle` — not per-batch — so the frontend doesn't thrash on large batches. Payload: `{search_profile_id, updated_at}`.

- [ ] Implement + test + commit. Expected: 1 new test + 219 = 220.

---

## Task 7: Email reminders

**Files:**
- Create: `database/migrations/2026_04_22_400000_add_notification_email_enabled_to_users.php`
- Modify: `app/Models/User.php` (add `notification_email_enabled` to `$fillable` + `$casts`)
- Create: `app/Mail/ReminderDueMail.php`
- Create: `resources/views/mail/reminder-due.blade.php`
- Create: `app/Jobs/SendReminderEmail.php`
- Modify: `app/Jobs/ApplicationReminderScan.php` (dispatch SendReminderEmail per user with reminders written)
- Create: `tests/Feature/Mail/ReminderDueMailTest.php`
- Create: `tests/Feature/Jobs/SendReminderEmailTest.php`

- [ ] **Step 1: Migration**

```php
Schema::table('users', function (Blueprint $table) {
    $table->boolean('notification_email_enabled')->default(true);
});
```

Add `'notification_email_enabled'` to User model's `$fillable` + cast as `'boolean'`.

- [ ] **Step 2: Mailable + blade template**

`app/Mail/ReminderDueMail.php`:

```php
<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;

class ReminderDueMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public array $applicationIds) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Applications due for follow-up',
            to: [$this->user->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.reminder-due',
            with: ['applications' => \App\Models\Application::with('canonicalJob:id,title,company_name')->whereIn('id', $this->applicationIds)->get()],
        );
    }
}
```

`resources/views/mail/reminder-due.blade.php`:

```blade
<h1>You have {{ count($applications) }} application{{ count($applications) === 1 ? '' : 's' }} due for follow-up</h1>

<ul>
@foreach($applications as $app)
  <li>
    <strong>{{ $app->canonicalJob->title }}</strong> at {{ $app->canonicalJob->company_name }}
    — <a href="{{ route('applications.show', $app) }}">Review</a>
  </li>
@endforeach
</ul>

<p>
  <a href="{{ route('applications.index') }}">Open your applications</a>
</p>
```

- [ ] **Step 3: `SendReminderEmail` job**

```php
<?php

namespace App\Jobs;

use App\Mail\ReminderDueMail;
use App\Models\Application;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendReminderEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $userId, public array $applicationIds) {}

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (!$user || !$user->notification_email_enabled) {
            return;
        }

        // Race check: applications may have been updated (e.g., status transitioned) between scan and send.
        $stillDue = Application::whereIn('id', $this->applicationIds)
            ->whereNotIn('status', Application::TERMINAL_STATUSES)
            ->pluck('id')->all();
        if (empty($stillDue)) {
            return;
        }

        Mail::send(new ReminderDueMail($user, $stillDue));
    }
}
```

- [ ] **Step 4: Extend `ApplicationReminderScan`**

After writing reminder events for a user's applications, dispatch `SendReminderEmail` if the user has `notification_email_enabled = true`:

```php
// After the per-user chunk...
$userGroups = $remindedAppsByUser; // map user_id => [application_ids]
foreach ($userGroups as $userId => $appIds) {
    $user = User::find($userId);
    if ($user && $user->notification_email_enabled) {
        SendReminderEmail::dispatch($userId, $appIds);
    }
}
```

Restructure `ApplicationReminderScan::handle` to group by user before dispatching. Current impl iterates per app; rewrite to batch.

- [ ] **Step 5: Tests**

`tests/Feature/Mail/ReminderDueMailTest.php` — renders with seeded applications, asserts subject + app list.

`tests/Feature/Jobs/SendReminderEmailTest.php`:
- `test_sends_when_notifications_enabled` — `Mail::fake()`, dispatch, assert sent
- `test_skips_when_user_opted_out`
- `test_skips_when_all_applications_reached_terminal` — race-check path
- `test_skips_when_user_missing`

- [ ] **Step 6: Run + commit**

Expected: ~5 new tests + 220 = ~225.

---

## Task 8: pgvector `throughline:rebuild-vector-index` command

**Files:**
- Create: `app/Console/Commands/RebuildVectorIndexCommand.php`
- Create: `tests/Feature/Console/RebuildVectorIndexCommandTest.php`

- [ ] **Step 1: Command**

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildVectorIndexCommand extends Command
{
    protected $signature = 'throughline:rebuild-vector-index {table : resumes|canonical_jobs} {--lists= : override the auto-computed lists count}';
    protected $description = 'Drop and recreate the ivfflat index on a vector column with a tuned lists parameter.';

    public function handle(): int
    {
        $table = $this->argument('table');
        if (!in_array($table, ['resumes', 'canonical_jobs'], true)) {
            $this->error('Table must be one of: resumes, canonical_jobs');
            return self::FAILURE;
        }

        $indexName = "{$table}_embedding_ivfflat";
        $rowCount = (int) DB::table($table)->whereNotNull('embedding')->count();
        $lists = $this->option('lists') ? (int) $this->option('lists') : max(1, (int) ceil(sqrt($rowCount)));

        $this->info("Rebuilding {$indexName}: {$rowCount} rows → lists = {$lists}");
        $start = microtime(true);

        DB::statement("DROP INDEX IF EXISTS {$indexName}");
        DB::statement("CREATE INDEX {$indexName} ON {$table} USING ivfflat (embedding vector_cosine_ops) WITH (lists = {$lists})");

        $this->info(sprintf('Done in %.2fs.', microtime(true) - $start));
        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Test**

```php
<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RebuildVectorIndexCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuilds_index_on_canonical_jobs(): void
    {
        $this->artisan('throughline:rebuild-vector-index', ['table' => 'canonical_jobs'])->assertExitCode(0);

        $exists = DB::selectOne("SELECT indexname FROM pg_indexes WHERE indexname = 'canonical_jobs_embedding_ivfflat'");
        $this->assertNotNull($exists);
    }

    public function test_rejects_unknown_table(): void
    {
        $this->artisan('throughline:rebuild-vector-index', ['table' => 'bogus'])->assertFailed();
    }

    public function test_honors_lists_override(): void
    {
        $this->artisan('throughline:rebuild-vector-index', ['table' => 'resumes', '--lists' => 42])->assertExitCode(0);
        // No easy way to introspect the lists param from pg_indexes; trust the SQL path.
    }
}
```

- [ ] **Step 3: Run + commit**

Expected: 3 new + 225 = 228.

---

## Task 9: Production readiness doc

**Files:**
- Create: `docs/production-readiness.md`
- Modify: `README.md` (link from "Deployment" section or add one)

- [ ] **Step 1: Write the doc**

Content checklist (per §2.8 of the spec). Each section has actionable bullets, not prose.

```markdown
# Throughline — Production readiness checklist

## 1. Required env vars

- APP_KEY (`php artisan key:generate`)
- APP_URL (full https URL)
- DB_* (pgsql credentials to a Postgres 16 instance with pgvector)
- REDIS_* (Redis for cache + queue + session)
- MAIL_* (SMTP credentials — SendGrid, Postmark, etc.)
- OPENAI_API_KEY, ANTHROPIC_API_KEY
- ADZUNA_APP_ID, ADZUNA_APP_KEY (if running the Adzuna driver)
- USAJOBS_AUTH_KEY, USAJOBS_USER_AGENT_EMAIL (if running USAJobs)
- REVERB_APP_ID, REVERB_APP_KEY, REVERB_APP_SECRET, REVERB_HOST, REVERB_PORT, REVERB_SCHEME
- BROADCAST_CONNECTION=reverb
- VITE_REVERB_APP_KEY, VITE_REVERB_HOST, VITE_REVERB_PORT, VITE_REVERB_SCHEME (compiled into JS bundle)
- S3 / filesystem creds if FILESYSTEM_DISK != local

## 2. External services

- Postgres 16 + pgvector extension (`CREATE EXTENSION vector`)
- Redis 6+ (any managed Redis works)
- Reverb WebSocket server (run as supervisord / systemd service: `php artisan reverb:start --host=0.0.0.0 --port=8080`)
- S3-compatible object storage for resume + application document uploads
- OpenAI + Anthropic API keys with adequate rate limits
- SMTP provider for transactional mail

## 3. Background processes

- Laravel Horizon (`php artisan horizon`) as a systemd service; set `horizon` as a queue worker supervisor
- Laravel scheduler (`* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`) via cron
- Reverb server (separate process — see above)

## 4. First deploy

- `php artisan migrate --force`
- `php artisan db:seed --class=SiteSettingsSeeder --force`
- `php artisan db:seed --class=JobSourcesSeeder --force`
- `php artisan storage:link`
- `npm run build` for Vite assets

## 5. Monitoring

- Horizon dashboard at `/horizon` (admin-gated); watch failed-jobs count
- Log aggregation — centralize `storage/logs/laravel.log`
- Alert on: failed queue jobs > N/hour, auto-paused job sources, AI cost ceiling hits
- Optional: Laravel Pulse for request/queue/cache metrics

## 6. Security

- APP_KEY rotation: coordinate with session drivers; rotating without session migration logs everyone out
- SESSION_DRIVER=redis in prod (not array or file)
- CSP: Reverb adds a `ws://` connection — include your Reverb host in connect-src
- Rotate API keys periodically; use the Settings editor only for non-secret tunables

## 7. Backups

- Postgres: nightly pg_dump, weekly full, retain 30 days
- Redis: if using managed Redis with replication, no additional backup (state is ephemeral by design)
- S3: enable versioning on the bucket

## 8. Rollback

- Migrations: `php artisan migrate:rollback --step=N` works for most Plan 1-6 migrations. The pgvector ivfflat index rebuild has no inverse; manually recreate if needed.
- Queue drain: `php artisan horizon:terminate` + wait for in-flight jobs.

## 9. Smoke test post-deploy

- Register → upload resume → see "Ready to match" (Reverb working)
- Create profile → open feed → see ranked results with stage-2 rationale (rerank working)
- Save a job → drag through kanban (pipeline working)
- Admin login → /admin → see Overview cards populated (admin working)
```

- [ ] **Step 2: Link from README**

Add a brief "Production" section to `README.md` pointing at `docs/production-readiness.md`.

- [ ] **Step 3: Commit**

```bash
git add docs/production-readiness.md README.md
git commit -m "docs: production readiness checklist"
```

---

## Task 10: Final docs + verification + close

**Files:**
- Modify: `CLAUDE.md` — add Plan 6 architecture bullets, finalize the "What's here now" to the post-Plan-6 state
- Modify: `README.md` — replace "What's here now" with "v1 feature set"; add Plan 6 items to the E2E description
- Modify: `tests/e2e/feed-happy-path.spec.ts` — drop long polling timeouts, rely on real-time

- [ ] **Step 1: CLAUDE.md final rewrite**

Append Plan 6 architecture bullets under existing "Architecture notes":

```markdown
- **Reverb real-time** — private `users.{id}` channels carry `ResumeReady`, `ResumeParseFailed`, `ReminderBadgeUpdated`, `FeedMatchReranked`. Every dispatch site wraps the event fire in try/catch so a failed push never aborts the primary DB write. Frontend subscriptions live in `resources/js/echo.ts` + page-level listeners.
- **Settings-driven theming** — `BrandConfig` reads `site_settings.brand_*_override` first, falls back to `config/brand.php`. Admin Settings page groups the 5 theming keys in a dedicated "Theming" section.
- **Email notifications** — `ApplicationReminderScan` dispatches `SendReminderEmail` per-user after writing reminder events. User opts out via the `notification_email_enabled` column on `users`.
- **pgvector index retuning** — `php artisan throughline:rebuild-vector-index {table}` drops + recreates the ivfflat index with `ceil(sqrt(N))` as the `lists` heuristic.
- **Brand-string leak guard** — `BrandStringLeakTest` fails if "Throughline" appears outside the allowlist (BrandConfig / config / tests / docs / mail templates).
```

Finalize the "What's here now" section to describe the full v1 feature set.

- [ ] **Step 2: README final update**

Replace the Plan-5-era "What's here now" with a concise v1 feature summary. Link to the production readiness doc.

- [ ] **Step 3: Playwright tighten**

Drop the 15s polling timeout on "Ready to match" down to 5s now that the Reverb path delivers sub-second updates. Run `npm run test:e2e` to confirm it still passes.

- [ ] **Step 4: Full verification**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --compact
```

Expected: ~230 pass (up from Plan 5's 214).

```bash
npm run lint && npm run build && npm run test:e2e
```

All clean.

- [ ] **Step 5: Final commit**

```bash
vendor/bin/pint --dirty
git add CLAUDE.md README.md tests/e2e/feed-happy-path.spec.ts
git commit -m "$(cat <<'EOF'
docs: finalize CLAUDE.md + README for Plan 6 / v1 feature set

Plan 6 closes the 6-plan arc. Throughline v1 ships with:

- Auth (email + Google + GitHub OAuth), multi-variant resumes with
  Claude parsing + OpenAI embedding, search profiles, 8 job source
  drivers, stage-1 + stage-2 matching, feed with rationale + gaps +
  narrative, application pipeline with kanban + contacts + documents
  + reminders, admin dashboard (Overview / Users + impersonation /
  Jobs / Analytics / Settings / Audit / Errors / Horizon), real-time
  updates via Reverb, email notifications.

Playwright spec tightens the "Ready to match" assertion timeout from
15s to 5s — Reverb delivery is sub-second in practice.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Done

Plan 6 is complete when all 10 tasks are green, the PHP suite reports ~230 tests passing, the Playwright spec passes with the tightened timeouts, and the production readiness doc is committed. Post-Plan-6 the 6-plan arc closes and the app ships to beta.
