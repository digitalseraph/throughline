# Throughline — Plan 1: Foundation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Produce a running, brand-coherent Laravel application at `web/throughline-web/` where a user can sign in via email/password, Google, or GitHub and land on an empty authenticated dashboard. All deployment-portability, brand, and admin-settings plumbing is in place so subsequent plans can hang features off a solid core.

**Architecture:** Laravel 13 + Inertia v3 + Vue 3 + Tailwind v4 (the **Laravel Vue Starter Kit** — the same template `improbica-web` uses), plus Laravel Socialite for Google + GitHub OAuth, a `SiteSettings` service backed by a DB table + Redis cache, and a `BrandConfig` service that resolves every brand-facing string and asset path. PostgreSQL 16 + Redis + Horizon wired in from day one (pgvector extension added by Plan 2 when resumes need it).

**Tech Stack:** PHP 8.4, Laravel 13, Fortify v1, Socialite, Horizon, Inertia Laravel v3, Inertia Vue v3, Wayfinder v0, Vue 3 + TypeScript, Tailwind v4, PHPUnit v12, Pint, Boost.

**Spec reference:** [`docs/superpowers/specs/2026-04-21-throughline-design.md`](../specs/2026-04-21-throughline-design.md)

> **Historical note — post-extraction:** This plan was executed while the project lived inside the `digitalseraph-meta` monorepo at `web/throughline-web/`. After Plan 1 shipped, the project was extracted to its own repository; all `web/throughline-web/...` paths in task descriptions below are now repo-root-relative (drop the prefix).
>
> **Stack reality deltas vs. the plan's Architecture/Tech-Stack line:** the published Laravel Vue Starter Kit shipped Laravel 12 + Inertia 2 + Tailwind v3 + Ziggy + PHPUnit 11 (not 13/3/v4/Wayfinder/12), and did not ship Fortify. The executor adapted accordingly: registration was wired into `RegisteredUserController::store()` rather than a Fortify action; Ziggy was used for JS route helpers; all Tailwind work used v3 idioms. See `CLAUDE.md` in the repo root for the authoritative current stack.

---

## Preconditions (check once before Task 1)

- PHP 8.4 available on PATH
- Composer 2.x on PATH
- Node 20+ / npm on PATH
- PostgreSQL 16 running locally with a superuser role for the developer
- Redis running locally on default port (6379)
- Monorepo clean working tree on `master`

Verify by running at the repo root:

```bash
php --version      # expect: PHP 8.4.x
composer --version # expect: Composer 2.x
node --version     # expect: v20+
psql --version     # expect: psql 16.x
redis-cli ping     # expect: PONG
```

---

## File Structure (what this plan will create)

Every path is relative to `E:/dev/repos/digitalseraph/web/throughline-web/` unless it starts with `/`.

**Scaffolded by the starter kit** (Task 1) — do not hand-write:
- `composer.json`, `package.json`, `.env.example`, `artisan`, `vite.config.ts`, `tsconfig.json`, `phpunit.xml`, `pint.json`
- `app/Http/`, `app/Models/User.php`, `app/Providers/`, `app/Actions/Fortify/*`
- `bootstrap/`, `config/*`, `database/`, `routes/*`, `resources/js/*`, `resources/css/app.css`, `resources/views/app.blade.php`, `tests/*`

**New / modified in this plan:**

| Path | Responsibility |
|---|---|
| `config/brand.php` | Static defaults for name, tagline, asset paths |
| `config/services.php` (modify) | Google + GitHub OAuth credentials |
| `resources/css/app.css` (modify) | Tailwind v4 `@theme` with Throughline tokens + Google Font import |
| `public/brand/wordmark.svg` | Standalone Throughline wordmark (Inter 800 + waypoint thread) |
| `public/brand/waypoint.svg` | Standalone waypoint thread for reuse (favicon, loading, dividers) |
| `public/favicon.ico` + `public/brand/favicon-*.png` + `public/site.webmanifest` | Favicon set |
| `resources/js/components/brand/Wordmark.vue` | Vue rendering of wordmark (size variants) |
| `resources/js/components/brand/WaypointThread.vue` | Vue SVG waypoint thread primitive |
| `resources/js/layouts/AuthLayout.vue` (modify) | Replace default with branded auth layout |
| `resources/js/layouts/AppLayout.vue` (modify) | Replace default with branded authenticated layout |
| `resources/js/pages/Welcome.vue` (replace) | Throughline landing page |
| `resources/js/pages/Dashboard.vue` (modify) | Empty branded authenticated dashboard |
| `resources/js/pages/auth/Login.vue` (modify) | Add Google + GitHub buttons |
| `resources/js/pages/auth/Register.vue` (modify) | Add Google + GitHub buttons |
| `database/migrations/YYYY_MM_DD_000001_add_role_to_users_table.php` | Add `role` enum to users |
| `database/migrations/YYYY_MM_DD_000002_create_social_accounts_table.php` | OAuth identity linkage |
| `database/migrations/YYYY_MM_DD_000003_create_site_settings_table.php` | Admin-tunable settings |
| `database/seeders/SiteSettingsSeeder.php` | Initial site_settings rows |
| `database/seeders/DatabaseSeeder.php` (modify) | Invoke `SiteSettingsSeeder` |
| `app/Models/SocialAccount.php` | Eloquent model for social identities |
| `app/Models/SiteSetting.php` | Eloquent model for settings |
| `app/Models/User.php` (modify) | `role`, `isAdmin()`, `socialAccounts()` |
| `app/Services/SiteSettings.php` | Singleton, Redis-backed settings accessor |
| `app/Services/Brand/BrandConfig.php` | Resolves name/tagline/asset paths from settings + config |
| `app/Actions/Fortify/CreateNewUser.php` (modify) | Promote first registered user to admin |
| `app/Http/Controllers/Auth/SocialiteController.php` | OAuth redirect + callback handler |
| `app/Providers/AppServiceProvider.php` (modify) | Bind `SiteSettings` + `BrandConfig` as singletons |
| `routes/web.php` (modify) | `auth.social.redirect` + `auth.social.callback` routes |
| `tests/Feature/Brand/BrandConfigTest.php` | BrandConfig defaults + overrides |
| `tests/Feature/SiteSettingsTest.php` | Caching + invalidation behavior |
| `tests/Feature/Auth/FirstAdminPromotionTest.php` | First user → admin |
| `tests/Feature/Auth/SocialiteGoogleTest.php` | Google OAuth flows |
| `tests/Feature/Auth/SocialiteGithubTest.php` | GitHub OAuth flows |
| `tests/Feature/Brand/NoHardcodedHostsTest.php` | Guard against host string leakage |
| `CLAUDE.md` | Project-specific instructions |
| `README.md` | Setup + dev commands |
| Monorepo `README.md` (modify) | Add `throughline-web` to the project table |

---

## Task 1: Scaffold the Laravel Vue Starter Kit

**Files:**
- Create: `web/throughline-web/` (entire project tree, via Laravel installer)
- Modify: `/README.md` (monorepo root, add a placeholder row — full update in Task 13)

- [ ] **Step 1: Create the project**

From the monorepo root (`E:/dev/repos/digitalseraph/`):

```bash
cd web
composer create-project laravel/vue-starter-kit throughline-web --no-interaction
cd throughline-web
```

Expected: project scaffolded; composer + npm install run automatically. A `.env` file is created by `post-create-project-cmd`.

- [ ] **Step 2: Configure environment for Postgres + Redis**

Edit `web/throughline-web/.env` — replace the DB and cache/queue sections with:

```env
APP_NAME=Throughline
APP_ENV=local
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=throughline
DB_USERNAME=postgres
DB_PASSWORD=

BROADCAST_DRIVER=log
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_FROM_ADDRESS="hello@throughline.local"
MAIL_FROM_NAME="${APP_NAME}"
```

Apply the same edits to `web/throughline-web/.env.example` (same keys, but with blank or sentinel values for secrets).

- [ ] **Step 3: Create the Postgres database**

```bash
createdb throughline
```

Expected: no output on success. If `createdb` prompts for a password, run `psql -U postgres -c "CREATE DATABASE throughline;"` instead.

- [ ] **Step 4: Install Socialite and Horizon via composer**

```bash
composer require laravel/socialite laravel/horizon predis/predis
php artisan horizon:install --no-interaction
```

Expected: packages added to `composer.json`; `config/horizon.php` published; `app/Providers/HorizonServiceProvider.php` created.

- [ ] **Step 5: Run migrations**

```bash
php artisan migrate --no-interaction
```

Expected: default users / sessions / cache / jobs / fortify tables created.

- [ ] **Step 6: Verify the dev stack boots**

```bash
composer run dev
```

Expected: concurrently starts `php artisan serve` (on 8000), `queue:listen`, and `npm run dev` (Vite on 5173). Visit `http://localhost:8000` in a browser and see the stock starter-kit welcome page. Stop the dev server (`Ctrl+C`).

- [ ] **Step 7: Commit**

From the monorepo root:

```bash
git add web/throughline-web
git commit -m "feat(throughline-web): scaffold Laravel Vue starter kit with Postgres + Redis

Base install via composer create-project laravel/vue-starter-kit.
Adds Socialite and Horizon. Configures Postgres + Redis.
"
```

---

## Task 2: Apply Throughline Tailwind theme

**Files:**
- Modify: `web/throughline-web/resources/css/app.css`

- [ ] **Step 1: Write the theme file**

Overwrite `web/throughline-web/resources/css/app.css` with:

```css
@import "tailwindcss";

@import url('https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..900;1,9..144,300..900&family=Inter:wght@300;400;500;600;700;800;900&family=IBM+Plex+Mono:wght@300;400;500;600&family=IBM+Plex+Serif:ital,wght@0,300;0,400;0,500;1,400&display=swap');

@source "../**/*.blade.php";
@source "../**/*.js";
@source "../**/*.ts";
@source "../**/*.vue";

@theme {
  /* Palette — oxblood is the single saturated accent */
  --color-bone: #fdfaf5;
  --color-bone-2: #f5ede0;
  --color-ink: #1a1410;
  --color-ink-2: #3a2e26;
  --color-ink-3: #6b5d52;
  --color-oxblood: #a83a1a;
  --color-hairline: rgba(26, 20, 16, 0.12);

  /* Type stack */
  --font-sans: 'Inter', system-ui, sans-serif;
  --font-display: 'Fraunces', 'IBM Plex Serif', Georgia, serif;
  --font-serif: 'IBM Plex Serif', Georgia, serif;
  --font-mono: 'IBM Plex Mono', ui-monospace, monospace;
}

@layer base {
  :root {
    color-scheme: light;
  }
  body {
    background-color: var(--color-bone);
    color: var(--color-ink);
    font-family: var(--font-sans);
    font-feature-settings: "cv05", "cv09", "cv11", "ss01";
  }
  .font-display {
    font-variation-settings: "opsz" 96, "SOFT" 30;
  }
}
```

- [ ] **Step 2: Rebuild assets to verify**

```bash
cd web/throughline-web
npm run build
```

Expected: Vite completes without errors. If Vite complains about a purge-source path, adjust the `@source` lines. Start `npm run dev` in another terminal if you want to eyeball it.

- [ ] **Step 3: Commit**

```bash
git add web/throughline-web/resources/css/app.css
git commit -m "feat(throughline-web): apply Throughline Tailwind v4 theme tokens"
```

---

## Task 3: Ship the Throughline wordmark assets

**Files:**
- Create: `web/throughline-web/public/brand/wordmark.svg`
- Create: `web/throughline-web/public/brand/waypoint.svg`
- Create: `web/throughline-web/public/brand/favicon.svg`
- Create: `web/throughline-web/public/favicon.ico` (copy of favicon.svg-derived 32×32)
- Create: `web/throughline-web/public/brand/favicon-16.png`, `-32.png`, `-48.png`, `-180.png`, `-512.png`
- Create: `web/throughline-web/public/site.webmanifest`
- Create: `web/throughline-web/resources/js/components/brand/Wordmark.vue`
- Create: `web/throughline-web/resources/js/components/brand/WaypointThread.vue`

- [ ] **Step 1: Write the full wordmark SVG**

`web/throughline-web/public/brand/wordmark.svg`:

```xml
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 820 150" role="img" aria-label="Throughline">
  <style>
    .wm { font-family: 'Inter', system-ui, sans-serif; font-weight: 800; letter-spacing: -0.045em; fill: #1a1410; }
  </style>
  <text class="wm" x="0" y="104" font-size="120">Throughline</text>
  <g fill="#a83a1a">
    <rect x="6" y="128" width="750" height="8" rx="4"/>
    <circle cx="10" cy="132" r="7"/>
    <circle cx="199" cy="132" r="7"/>
    <circle cx="388" cy="132" r="7"/>
    <circle cx="577" cy="132" r="7"/>
    <circle cx="752" cy="132" r="7"/>
  </g>
</svg>
```

- [ ] **Step 2: Write the standalone waypoint SVG**

`web/throughline-web/public/brand/waypoint.svg`:

```xml
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 560 14" role="img" aria-label="Throughline waypoint thread">
  <line x1="6" y1="7" x2="554" y2="7" stroke="#a83a1a" stroke-width="4" stroke-linecap="round"/>
  <circle cx="6" cy="7" r="6" fill="#a83a1a"/>
  <circle cx="142" cy="7" r="6" fill="#a83a1a"/>
  <circle cx="280" cy="7" r="6" fill="#a83a1a"/>
  <circle cx="418" cy="7" r="6" fill="#a83a1a"/>
  <circle cx="554" cy="7" r="6" fill="#a83a1a"/>
</svg>
```

- [ ] **Step 3: Write the favicon SVG**

A simplified 3-waypoint mark, suitable for tiny sizes. `web/throughline-web/public/brand/favicon.svg`:

```xml
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
  <rect width="64" height="64" rx="12" fill="#fdfaf5"/>
  <line x1="12" y1="32" x2="52" y2="32" stroke="#a83a1a" stroke-width="6" stroke-linecap="round"/>
  <circle cx="12" cy="32" r="8" fill="#a83a1a"/>
  <circle cx="32" cy="32" r="8" fill="#a83a1a"/>
  <circle cx="52" cy="32" r="8" fill="#a83a1a"/>
</svg>
```

- [ ] **Step 4: Generate raster favicons from the SVG**

Use ImageMagick (or any SVG-to-PNG tool):

```bash
cd web/throughline-web/public/brand
magick -background none favicon.svg -resize 16x16 favicon-16.png
magick -background none favicon.svg -resize 32x32 favicon-32.png
magick -background none favicon.svg -resize 48x48 favicon-48.png
magick -background none favicon.svg -resize 180x180 favicon-180.png
magick -background none favicon.svg -resize 512x512 favicon-512.png
magick -background none favicon.svg -resize 32x32 ../favicon.ico
```

Expected: five PNGs plus `public/favicon.ico`. If `magick` isn't installed, any equivalent (e.g. `rsvg-convert`, `inkscape --export-type=png`) works — the final file names and sizes must match.

- [ ] **Step 5: Write the web manifest**

`web/throughline-web/public/site.webmanifest`:

```json
{
  "name": "Throughline",
  "short_name": "Throughline",
  "icons": [
    { "src": "/brand/favicon-180.png", "sizes": "180x180", "type": "image/png" },
    { "src": "/brand/favicon-512.png", "sizes": "512x512", "type": "image/png" }
  ],
  "theme_color": "#a83a1a",
  "background_color": "#fdfaf5",
  "display": "standalone"
}
```

- [ ] **Step 6: Write the WaypointThread Vue component**

`web/throughline-web/resources/js/components/brand/WaypointThread.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  width?: number
  height?: number
  dotCount?: number
  color?: string
}
const props = withDefaults(defineProps<Props>(), {
  width: 240,
  height: 10,
  dotCount: 5,
  color: 'var(--color-oxblood)',
})

const positions = computed<number[]>(() => {
  const pad = props.height / 2
  const span = props.width - pad * 2
  const count = Math.max(2, props.dotCount)
  const out: number[] = []
  for (let i = 0; i < count; i++) {
    out.push(pad + (span * i) / (count - 1))
  }
  return out
})

const radius = computed(() => props.height / 2.2)
const strokeWidth = computed(() => props.height / 3)
</script>

<template>
  <svg
    :width="width"
    :height="height"
    :viewBox="`0 0 ${width} ${height}`"
    class="overflow-visible"
    role="img"
    aria-label="Throughline waypoint thread"
  >
    <line
      :x1="height / 2"
      :y1="height / 2"
      :x2="width - height / 2"
      :y2="height / 2"
      :stroke="color"
      :stroke-width="strokeWidth"
      stroke-linecap="round"
    />
    <circle
      v-for="(cx, i) in positions"
      :key="i"
      :cx="cx"
      :cy="height / 2"
      :r="radius"
      :fill="color"
    />
  </svg>
</template>
```

- [ ] **Step 7: Write the Wordmark Vue component**

`web/throughline-web/resources/js/components/brand/Wordmark.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue'
import WaypointThread from './WaypointThread.vue'

interface Props {
  size?: 'sm' | 'md' | 'lg' | 'xl'
}
const props = withDefaults(defineProps<Props>(), { size: 'md' })

const textClass = computed(() => ({
  sm: 'text-2xl',
  md: 'text-4xl',
  lg: 'text-6xl',
  xl: 'text-8xl',
}[props.size]))

const threadWidth = computed(() => ({ sm: 120, md: 200, lg: 320, xl: 440 }[props.size]))
const threadHeight = computed(() => ({ sm: 6, md: 8, lg: 11, xl: 14 }[props.size]))
</script>

<template>
  <div class="inline-flex flex-col items-start leading-none select-none">
    <span
      :class="[textClass, 'font-sans font-extrabold text-ink']"
      style="letter-spacing: -0.045em;"
    >Throughline</span>
    <WaypointThread
      class="mt-1.5"
      :width="threadWidth"
      :height="threadHeight"
      :dot-count="5"
    />
  </div>
</template>
```

- [ ] **Step 8: Wire favicons into the HTML head**

Edit `web/throughline-web/resources/views/app.blade.php`. Inside `<head>`, add (immediately after the `<title>` tag):

```blade
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="icon" type="image/svg+xml" href="/brand/favicon.svg">
<link rel="apple-touch-icon" href="/brand/favicon-180.png">
<link rel="manifest" href="/site.webmanifest">
<meta name="theme-color" content="#a83a1a">
```

- [ ] **Step 9: Commit**

```bash
git add web/throughline-web/public web/throughline-web/resources/js/components/brand web/throughline-web/resources/views/app.blade.php
git commit -m "feat(throughline-web): ship Throughline wordmark + waypoint assets"
```

---

## Task 4: BrandConfig service with test

**Files:**
- Create: `web/throughline-web/config/brand.php`
- Create: `web/throughline-web/app/Services/Brand/BrandConfig.php`
- Modify: `web/throughline-web/app/Providers/AppServiceProvider.php`
- Create: `web/throughline-web/tests/Feature/Brand/BrandConfigTest.php`

- [ ] **Step 1: Write the failing test**

`web/throughline-web/tests/Feature/Brand/BrandConfigTest.php`:

```php
<?php

namespace Tests\Feature\Brand;

use App\Services\Brand\BrandConfig;
use App\Services\SiteSettings;
use Tests\TestCase;

class BrandConfigTest extends TestCase
{
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
```

- [ ] **Step 2: Run the test to confirm it fails**

```bash
cd web/throughline-web
php artisan test --compact --filter=BrandConfigTest
```

Expected: errors because `App\Services\Brand\BrandConfig` and `App\Services\SiteSettings` don't exist yet.

- [ ] **Step 3: Write the brand config file**

`web/throughline-web/config/brand.php`:

```php
<?php

return [
    'name' => env('BRAND_NAME', 'Throughline'),
    'tagline' => env('BRAND_TAGLINE', 'Every career has a thread. Apply to jobs that follow yours.'),

    'assets' => [
        'wordmark' => '/brand/wordmark.svg',
        'waypoint' => '/brand/waypoint.svg',
        'favicon' => '/brand/favicon.svg',
    ],
];
```

- [ ] **Step 4: Write the BrandConfig service**

`web/throughline-web/app/Services/Brand/BrandConfig.php`:

```php
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
```

- [ ] **Step 5: Write a stub SiteSettings so the test can resolve it**

We'll flesh it out in Task 5, but the binding needs to exist now.

`web/throughline-web/app/Services/SiteSettings.php`:

```php
<?php

namespace App\Services;

class SiteSettings
{
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}
```

- [ ] **Step 6: Bind both services as singletons**

Edit `web/throughline-web/app/Providers/AppServiceProvider.php`. Add to the `register()` method:

```php
$this->app->singleton(\App\Services\SiteSettings::class);
$this->app->singleton(\App\Services\Brand\BrandConfig::class);
```

- [ ] **Step 7: Run the test to confirm it passes**

```bash
php artisan test --compact --filter=BrandConfigTest
```

Expected: all three tests PASS.

- [ ] **Step 8: Format + commit**

```bash
vendor/bin/pint --dirty --format agent
git add web/throughline-web/config/brand.php web/throughline-web/app/Services web/throughline-web/app/Providers/AppServiceProvider.php web/throughline-web/tests/Feature/Brand/BrandConfigTest.php
git commit -m "feat(throughline-web): BrandConfig service with config + settings fallback"
```

---

## Task 5: SiteSettings table, model, and cached service

**Files:**
- Create: `web/throughline-web/database/migrations/YYYY_MM_DD_000003_create_site_settings_table.php` (actual timestamp from `date +%Y_%m_%d_%H%M%S`)
- Create: `web/throughline-web/app/Models/SiteSetting.php`
- Modify: `web/throughline-web/app/Services/SiteSettings.php`
- Create: `web/throughline-web/database/seeders/SiteSettingsSeeder.php`
- Modify: `web/throughline-web/database/seeders/DatabaseSeeder.php`
- Create: `web/throughline-web/tests/Feature/SiteSettingsTest.php`

- [ ] **Step 1: Generate the migration**

```bash
cd web/throughline-web
php artisan make:migration create_site_settings_table --no-interaction
```

Replace the generated file's `up()` body with:

```php
Schema::create('site_settings', function (Blueprint $table) {
    $table->id();
    $table->string('key')->unique();
    $table->json('value')->nullable();
    $table->string('type')->default('string');
    $table->text('description')->nullable();
    $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
});
```

- [ ] **Step 2: Generate the model**

```bash
php artisan make:model SiteSetting --no-interaction
```

Replace `app/Models/SiteSetting.php` with:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'description', 'updated_by'];

    protected $casts = [
        'value' => 'array',
    ];
}
```

- [ ] **Step 3: Write the failing test**

`web/throughline-web/tests/Feature/SiteSettingsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_default_when_key_missing(): void
    {
        $settings = app(SiteSettings::class);

        $this->assertSame('fallback', $settings->get('unknown', 'fallback'));
    }

    public function test_returns_db_value_when_present(): void
    {
        SiteSetting::create(['key' => 'site_name', 'value' => 'Custom', 'type' => 'string']);

        $settings = app(SiteSettings::class);

        $this->assertSame('Custom', $settings->get('site_name'));
    }

    public function test_set_writes_to_db_and_invalidates_cache(): void
    {
        $settings = app(SiteSettings::class);
        $settings->get('site_name');
        $this->assertTrue(Cache::has('site_settings:all'));

        $settings->set('site_name', 'Updated');

        $this->assertFalse(Cache::has('site_settings:all'));
        $this->assertSame('Updated', $settings->get('site_name'));
        $this->assertDatabaseHas('site_settings', ['key' => 'site_name']);
    }

    public function test_subsequent_reads_hit_cache(): void
    {
        SiteSetting::create(['key' => 'site_name', 'value' => 'First', 'type' => 'string']);

        $settings = app(SiteSettings::class);
        $this->assertSame('First', $settings->get('site_name'));

        SiteSetting::where('key', 'site_name')->update(['value' => json_encode('Sneaky')]);

        $this->assertSame('First', $settings->get('site_name'), 'should be served from cache');
    }
}
```

- [ ] **Step 4: Run the test to confirm it fails**

```bash
php artisan test --compact --filter=SiteSettingsTest
```

Expected: failures — `SiteSettings::get` returns the default but there's no DB reading yet, no cache behavior.

- [ ] **Step 5: Implement the real service**

Replace `web/throughline-web/app/Services/SiteSettings.php` with:

```php
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
```

- [ ] **Step 6: Write the seeder**

`web/throughline-web/database/seeders/SiteSettingsSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\SiteSetting;
use Illuminate\Database\Seeder;

class SiteSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['key' => 'site_name', 'value' => 'Throughline', 'type' => 'string', 'description' => 'Public product name.'],
            ['key' => 'site_tagline', 'value' => 'Every career has a thread. Apply to jobs that follow yours.', 'type' => 'string', 'description' => 'Tagline shown on marketing surfaces.'],
            ['key' => 'signups_enabled', 'value' => true, 'type' => 'bool', 'description' => 'Whether new-user registration is accepted.'],
            ['key' => 'maintenance_mode', 'value' => false, 'type' => 'bool', 'description' => 'Show a maintenance banner / block non-admin access.'],
            ['key' => 'default_source_cadence_minutes', 'value' => 60, 'type' => 'int', 'description' => 'Default cadence for new job-source rows.'],
            ['key' => 'matching_top_k', 'value' => 30, 'type' => 'int', 'description' => 'How many stage-1 matches to keep per profile.'],
            ['key' => 'rerank_shortlist_size', 'value' => 20, 'type' => 'int', 'description' => 'Batch size for stage-2 LLM rerank.'],
            ['key' => 'rerank_enabled', 'value' => true, 'type' => 'bool', 'description' => 'Master switch for stage-2 rerank.'],
            ['key' => 'cost_ceiling_per_user_per_day_usd', 'value' => 2.0, 'type' => 'string', 'description' => 'Per-user daily AI cost cap in USD.'],
            ['key' => 'brand_asset_overrides', 'value' => [], 'type' => 'json', 'description' => 'Optional per-install brand asset paths.'],
        ];

        foreach ($defaults as $row) {
            SiteSetting::firstOrCreate(['key' => $row['key']], $row);
        }
    }
}
```

- [ ] **Step 7: Invoke the seeder from DatabaseSeeder**

Edit `web/throughline-web/database/seeders/DatabaseSeeder.php`. Inside `run()`, replace any existing body with:

```php
$this->call([
    SiteSettingsSeeder::class,
]);
```

- [ ] **Step 8: Migrate + seed**

```bash
php artisan migrate --no-interaction
php artisan db:seed --no-interaction
```

Expected: `site_settings` table exists with ~10 rows.

- [ ] **Step 9: Run the tests to confirm they pass**

```bash
php artisan test --compact --filter=SiteSettingsTest
```

Expected: all four tests PASS. Re-run the `BrandConfigTest` suite too to confirm nothing broke:

```bash
php artisan test --compact --filter=BrandConfigTest
```

- [ ] **Step 10: Format + commit**

```bash
vendor/bin/pint --dirty --format agent
git add web/throughline-web/database web/throughline-web/app/Services/SiteSettings.php web/throughline-web/app/Models/SiteSetting.php web/throughline-web/tests/Feature/SiteSettingsTest.php
git commit -m "feat(throughline-web): SiteSettings table + Redis-cached service + seeded defaults"
```

---

## Task 6: Add `role` to users + first-admin promotion

**Files:**
- Create: `web/throughline-web/database/migrations/YYYY_MM_DD_000001_add_role_to_users_table.php`
- Modify: `web/throughline-web/app/Models/User.php`
- Modify: `web/throughline-web/app/Actions/Fortify/CreateNewUser.php`
- Create: `web/throughline-web/tests/Feature/Auth/FirstAdminPromotionTest.php`

- [ ] **Step 1: Generate the migration**

```bash
cd web/throughline-web
php artisan make:migration add_role_to_users_table --no-interaction
```

Replace the `up()` body:

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('role')->default('user')->after('email_verified_at');
    $table->timestamp('last_login_at')->nullable()->after('role');
});
```

And the `down()` body:

```php
Schema::table('users', function (Blueprint $table) {
    $table->dropColumn(['role', 'last_login_at']);
});
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate --no-interaction
```

- [ ] **Step 3: Update the User model**

Edit `web/throughline-web/app/Models/User.php`. Add `'role'` and `'last_login_at'` to `$fillable`, add a cast for `last_login_at`, and add these methods inside the class:

```php
public function isAdmin(): bool
{
    return $this->role === 'admin';
}

public function socialAccounts(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(\App\Models\SocialAccount::class);
}
```

The final `$fillable` / `$casts` in User should look like:

```php
protected $fillable = [
    'name',
    'email',
    'password',
    'role',
    'last_login_at',
];

protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password' => 'hashed',
    ];
}
```

- [ ] **Step 4: Write the failing test**

`web/throughline-web/tests/Feature/Auth/FirstAdminPromotionTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirstAdminPromotionTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_registered_user_becomes_admin(): void
    {
        $action = app(CreateNewUser::class);

        $user = $action->create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->isAdmin());
    }

    public function test_second_registered_user_stays_as_regular_user(): void
    {
        $action = app(CreateNewUser::class);

        User::factory()->create(['role' => 'admin']);

        $user = $action->create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        $this->assertSame('user', $user->role);
        $this->assertFalse($user->isAdmin());
    }
}
```

- [ ] **Step 5: Run the test to confirm it fails**

```bash
php artisan test --compact --filter=FirstAdminPromotionTest
```

Expected: FAIL — the starter kit's `CreateNewUser` doesn't set `role`.

- [ ] **Step 6: Update CreateNewUser**

Open `web/throughline-web/app/Actions/Fortify/CreateNewUser.php`. Replace the contents of `create()` with:

```php
public function create(array $input): User
{
    Validator::make($input, [
        'name' => ['required', 'string', 'max:255'],
        'email' => [
            'required',
            'string',
            'email',
            'max:255',
            Rule::unique(User::class),
        ],
        'password' => $this->passwordRules(),
    ])->validate();

    return User::create([
        'name' => $input['name'],
        'email' => $input['email'],
        'password' => Hash::make($input['password']),
        'role' => User::count() === 0 ? 'admin' : 'user',
    ]);
}
```

(Leave the existing `use` statements in place; they already include `Hash`, `Validator`, `Rule`, `User`, and the `PasswordValidationRules` trait providing `passwordRules()`.)

- [ ] **Step 7: Update the User factory to include role**

Edit `web/throughline-web/database/factories/UserFactory.php`. In the `definition()` array, add:

```php
'role' => 'user',
'last_login_at' => null,
```

- [ ] **Step 8: Run the test to confirm it passes**

```bash
php artisan test --compact --filter=FirstAdminPromotionTest
```

Expected: both tests PASS.

- [ ] **Step 9: Format + commit**

```bash
vendor/bin/pint --dirty --format agent
git add web/throughline-web/database/migrations web/throughline-web/database/factories web/throughline-web/app/Models/User.php web/throughline-web/app/Actions/Fortify/CreateNewUser.php web/throughline-web/tests/Feature/Auth
git commit -m "feat(throughline-web): role column + first-user-to-admin promotion"
```

---

## Task 7: social_accounts table + model

**Files:**
- Create: `web/throughline-web/database/migrations/YYYY_MM_DD_000002_create_social_accounts_table.php`
- Create: `web/throughline-web/app/Models/SocialAccount.php`
- Create: `web/throughline-web/database/factories/SocialAccountFactory.php`

- [ ] **Step 1: Generate the migration**

```bash
cd web/throughline-web
php artisan make:migration create_social_accounts_table --no-interaction
```

Replace the `up()` body:

```php
Schema::create('social_accounts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('provider');
    $table->string('provider_user_id');
    $table->text('access_token')->nullable();
    $table->text('refresh_token')->nullable();
    $table->timestamps();

    $table->unique(['provider', 'provider_user_id']);
    $table->index(['user_id', 'provider']);
});
```

- [ ] **Step 2: Generate model + factory**

```bash
php artisan make:model SocialAccount --factory --no-interaction
```

- [ ] **Step 3: Write the model**

Replace `app/Models/SocialAccount.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'access_token',
        'refresh_token',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 4: Write the factory**

Replace `database/factories/SocialAccountFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SocialAccountFactory extends Factory
{
    protected $model = SocialAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => fake()->randomElement(['google', 'github']),
            'provider_user_id' => (string) fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'access_token' => fake()->sha256(),
            'refresh_token' => fake()->sha256(),
        ];
    }
}
```

- [ ] **Step 5: Run migration**

```bash
php artisan migrate --no-interaction
```

Expected: `social_accounts` table created.

- [ ] **Step 6: Smoke-test encryption via a quick unit test**

Create `web/throughline-web/tests/Feature/SocialAccountEncryptionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SocialAccountEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_are_encrypted_at_rest(): void
    {
        $account = SocialAccount::factory()->create([
            'access_token' => 'plaintext-access',
            'refresh_token' => 'plaintext-refresh',
        ]);

        $raw = DB::table('social_accounts')->where('id', $account->id)->first();

        $this->assertNotSame('plaintext-access', $raw->access_token);
        $this->assertNotSame('plaintext-refresh', $raw->refresh_token);
        $this->assertSame('plaintext-access', $account->fresh()->access_token);
        $this->assertSame('plaintext-refresh', $account->fresh()->refresh_token);
    }
}
```

- [ ] **Step 7: Run it**

```bash
php artisan test --compact --filter=SocialAccountEncryptionTest
```

Expected: PASS.

- [ ] **Step 8: Format + commit**

```bash
vendor/bin/pint --dirty --format agent
git add web/throughline-web/database web/throughline-web/app/Models/SocialAccount.php web/throughline-web/tests/Feature/SocialAccountEncryptionTest.php
git commit -m "feat(throughline-web): social_accounts table with encrypted tokens"
```

---

## Task 8: Socialite Google + GitHub OAuth

**Files:**
- Modify: `web/throughline-web/config/services.php`
- Modify: `web/throughline-web/.env.example` (and `.env` for local dev)
- Create: `web/throughline-web/app/Http/Controllers/Auth/SocialiteController.php`
- Modify: `web/throughline-web/routes/web.php`
- Create: `web/throughline-web/tests/Feature/Auth/SocialiteGoogleTest.php`
- Create: `web/throughline-web/tests/Feature/Auth/SocialiteGithubTest.php`

- [ ] **Step 1: Add Google + GitHub entries in config/services.php**

Append to the returned array in `web/throughline-web/config/services.php`:

```php
'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect' => env('APP_URL').'/auth/google/callback',
],

'github' => [
    'client_id' => env('GITHUB_CLIENT_ID'),
    'client_secret' => env('GITHUB_CLIENT_SECRET'),
    'redirect' => env('APP_URL').'/auth/github/callback',
],
```

Add to `.env.example` and `.env`:

```env
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=
```

- [ ] **Step 2: Write the failing Google test**

`web/throughline-web/tests/Feature/Auth/SocialiteGoogleTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class SocialiteGoogleTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirect_route_hands_off_to_google(): void
    {
        $response = $this->get('/auth/google/redirect');

        $response->assertStatus(302);
        $this->assertStringContainsString('google', $response->headers->get('Location'));
    }

    public function test_callback_creates_user_and_social_account_and_promotes_first_user_to_admin(): void
    {
        $socialUser = $this->fakeSocialiteUser([
            'id' => 'g-123',
            'email' => 'alice@example.com',
            'name' => 'Alice Anderson',
            'token' => 'acc-tok',
            'refreshToken' => 'ref-tok',
        ]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', ['email' => 'alice@example.com', 'role' => 'admin']);
        $this->assertDatabaseHas('social_accounts', ['provider' => 'google', 'provider_user_id' => 'g-123']);
    }

    public function test_callback_links_existing_user_by_email(): void
    {
        $existing = User::factory()->create(['email' => 'alice@example.com', 'role' => 'user']);

        $socialUser = $this->fakeSocialiteUser([
            'id' => 'g-999',
            'email' => 'alice@example.com',
            'name' => 'Alice A.',
        ]);
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertSame(1, User::count());
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $existing->id,
            'provider' => 'google',
            'provider_user_id' => 'g-999',
        ]);
    }

    public function test_callback_handles_provider_failure_gracefully(): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andThrow(new \RuntimeException('oauth boom'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertSame(0, User::count());
    }

    private function fakeSocialiteUser(array $attrs): SocialiteUser
    {
        $user = new SocialiteUser();
        $user->id = $attrs['id'];
        $user->email = $attrs['email'];
        $user->name = $attrs['name'] ?? null;
        $user->token = $attrs['token'] ?? 'token';
        $user->refreshToken = $attrs['refreshToken'] ?? null;

        return $user;
    }
}
```

- [ ] **Step 3: Write the failing GitHub test**

`web/throughline-web/tests/Feature/Auth/SocialiteGithubTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class SocialiteGithubTest extends TestCase
{
    use RefreshDatabase;

    public function test_callback_creates_user_from_github(): void
    {
        $socialUser = new SocialiteUser();
        $socialUser->id = 'gh-42';
        $socialUser->email = 'dev@example.com';
        $socialUser->name = 'Dev User';
        $socialUser->nickname = 'devuser';
        $socialUser->token = 'tok';
        $socialUser->refreshToken = null;

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with('github')->andReturn($provider);

        $response = $this->get('/auth/github/callback');

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', ['email' => 'dev@example.com']);
        $this->assertDatabaseHas('social_accounts', ['provider' => 'github', 'provider_user_id' => 'gh-42']);
    }

    public function test_unknown_provider_returns_404(): void
    {
        $this->get('/auth/facebook/redirect')->assertNotFound();
        $this->get('/auth/facebook/callback')->assertNotFound();
    }

    public function test_user_can_have_both_providers_linked(): void
    {
        $user = User::factory()->create(['email' => 'multi@example.com']);

        foreach (['google' => 'g-1', 'github' => 'gh-1'] as $provider => $providerId) {
            $socialUser = new SocialiteUser();
            $socialUser->id = $providerId;
            $socialUser->email = 'multi@example.com';
            $socialUser->name = 'Multi';
            $socialUser->token = 'tok';
            $socialUser->refreshToken = null;

            $providerMock = Mockery::mock(Provider::class);
            $providerMock->shouldReceive('user')->andReturn($socialUser);
            Socialite::shouldReceive('driver')->with($provider)->andReturn($providerMock);

            $this->get("/auth/{$provider}/callback");
        }

        $this->assertSame(1, User::count());
        $this->assertSame(2, $user->fresh()->socialAccounts()->count());
    }
}
```

- [ ] **Step 4: Run the tests to confirm they fail**

```bash
php artisan test --compact --filter="SocialiteGoogleTest|SocialiteGithubTest"
```

Expected: all FAIL (routes don't exist, controller doesn't exist).

- [ ] **Step 5: Create the SocialiteController**

`web/throughline-web/app/Http/Controllers/Auth/SocialiteController.php`:

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SocialiteController extends Controller
{
    private const ALLOWED_PROVIDERS = ['google', 'github'];

    public function redirect(string $provider): Response
    {
        $this->guardProvider($provider);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        $this->guardProvider($provider);

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (Throwable) {
            return redirect()->route('login')->with('error', 'Sign-in failed. Please try again.');
        }

        if (! $socialUser->getEmail()) {
            return redirect()->route('login')->with('error', 'Provider did not return an email address.');
        }

        $user = DB::transaction(function () use ($provider, $socialUser) {
            $account = SocialAccount::where('provider', $provider)
                ->where('provider_user_id', (string) $socialUser->getId())
                ->first();

            if ($account) {
                $account->update([
                    'access_token' => $socialUser->token,
                    'refresh_token' => $socialUser->refreshToken,
                ]);

                return $account->user;
            }

            $user = User::where('email', $socialUser->getEmail())->first()
                ?? User::create([
                    'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? $socialUser->getEmail(),
                    'email' => $socialUser->getEmail(),
                    'email_verified_at' => now(),
                    'password' => null,
                    'role' => User::count() === 0 ? 'admin' : 'user',
                ]);

            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_user_id' => (string) $socialUser->getId(),
                'access_token' => $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
            ]);

            return $user;
        });

        $user->forceFill(['last_login_at' => now()])->save();

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }

    private function guardProvider(string $provider): void
    {
        abort_unless(in_array($provider, self::ALLOWED_PROVIDERS, true), 404);
    }
}
```

- [ ] **Step 6: Register the routes**

Edit `web/throughline-web/routes/web.php`. Add inside the group that uses the `guest` middleware (or create one if there isn't one — see the existing file and follow its idiom). Use:

```php
use App\Http\Controllers\Auth\SocialiteController;

Route::middleware('guest')->group(function () {
    Route::get('auth/{provider}/redirect', [SocialiteController::class, 'redirect'])
        ->where('provider', 'google|github')
        ->name('auth.social.redirect');

    Route::get('auth/{provider}/callback', [SocialiteController::class, 'callback'])
        ->where('provider', 'google|github')
        ->name('auth.social.callback');
});
```

- [ ] **Step 7: Verify users.password can be null for OAuth-only users**

Open the original `create_users_table` migration (it's in `database/migrations/`) and confirm the `password` column is defined with `->nullable()`. The starter kit usually makes it non-nullable — if so, add a follow-up migration:

```bash
php artisan make:migration make_password_nullable_on_users --no-interaction
```

With `up()`:

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('password')->nullable()->change();
});
```

And `down()`:

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('password')->nullable(false)->change();
});
```

Run:

```bash
php artisan migrate --no-interaction
```

- [ ] **Step 8: Run the tests to confirm they pass**

```bash
php artisan test --compact --filter="SocialiteGoogleTest|SocialiteGithubTest"
```

Expected: all PASS.

- [ ] **Step 9: Format + commit**

```bash
vendor/bin/pint --dirty --format agent
git add web/throughline-web
git commit -m "feat(throughline-web): Socialite Google + GitHub OAuth with account linking"
```

---

## Task 9: OAuth buttons on Login + Register pages

**Files:**
- Modify: `web/throughline-web/resources/js/pages/auth/Login.vue`
- Modify: `web/throughline-web/resources/js/pages/auth/Register.vue`
- Create: `web/throughline-web/resources/js/components/auth/OAuthButtons.vue`

> Note: the Vue Starter Kit's auth pages live at `resources/js/pages/auth/`. If the path differs in your install, use the actual directory you see — Inertia v3 uses lowercase `pages/` per the project conventions.

- [ ] **Step 1: Create the OAuth buttons component**

`web/throughline-web/resources/js/components/auth/OAuthButtons.vue`:

```vue
<script setup lang="ts">
const providers = [
  { key: 'google', label: 'Continue with Google' },
  { key: 'github', label: 'Continue with GitHub' },
] as const
</script>

<template>
  <div class="flex flex-col gap-2 mt-4">
    <a
      v-for="p in providers"
      :key="p.key"
      :href="`/auth/${p.key}/redirect`"
      class="inline-flex items-center justify-center gap-2 h-10 px-4 rounded-md border border-[color:var(--color-hairline)] bg-bone hover:bg-bone-2 text-ink font-medium transition-colors"
    >
      <span class="inline-block w-2 h-2 rounded-full bg-oxblood"></span>
      {{ p.label }}
    </a>
  </div>
  <div class="relative my-4">
    <div class="absolute inset-0 flex items-center">
      <div class="w-full border-t border-[color:var(--color-hairline)]"></div>
    </div>
    <div class="relative flex justify-center">
      <span class="bg-bone px-3 text-xs font-mono tracking-widest text-ink-3 uppercase">or</span>
    </div>
  </div>
</template>
```

- [ ] **Step 2: Add the component to the Login page**

Open `web/throughline-web/resources/js/pages/auth/Login.vue`. Import and render `OAuthButtons` directly above the email/password form:

```vue
<script setup lang="ts">
import OAuthButtons from '@/components/auth/OAuthButtons.vue'
// ...existing imports
</script>

<template>
  <!-- existing layout wrapper -->
  <OAuthButtons />
  <!-- existing form -->
</template>
```

(Preserve all existing form code — only add the import and render the component above the form.)

- [ ] **Step 3: Add the component to the Register page**

Do the same in `web/throughline-web/resources/js/pages/auth/Register.vue`.

- [ ] **Step 4: Visual smoke check**

```bash
cd web/throughline-web
composer run dev
```

Open `http://localhost:8000/login` and `http://localhost:8000/register`. Expect: two OAuth buttons above each form, oxblood-accented. Click "Continue with Google" — you'll get a 500 about missing credentials; that's expected. OAuth provider setup is a deployment-time concern.

- [ ] **Step 5: Commit**

```bash
git add web/throughline-web/resources/js
git commit -m "feat(throughline-web): OAuth buttons on login + register pages"
```

---

## Task 10: Brand the layouts + replace welcome page

**Files:**
- Modify: `web/throughline-web/resources/js/pages/Welcome.vue` (or equivalent landing)
- Modify: `web/throughline-web/resources/js/pages/Dashboard.vue`
- Modify: `web/throughline-web/resources/js/layouts/AppLayout.vue`
- Modify: `web/throughline-web/resources/js/layouts/AuthLayout.vue`

> If the starter kit uses different layout filenames (e.g. `AppSidebarLayout.vue`, `AuthSimpleLayout.vue`), inspect `resources/js/layouts/` first and apply the same edits to the files that exist. The *names* may differ — the changes are the same: replace the logo/app-title area with the Throughline wordmark, and apply the bone/ink/oxblood palette to the chrome.

- [ ] **Step 1: Replace the landing page**

Overwrite `web/throughline-web/resources/js/pages/Welcome.vue`:

```vue
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import Wordmark from '@/components/brand/Wordmark.vue'
import WaypointThread from '@/components/brand/WaypointThread.vue'

defineProps<{
  canLogin: boolean
  canRegister: boolean
  siteName: string
  tagline: string
}>()
</script>

<template>
  <Head title="Throughline" />

  <div class="min-h-screen bg-bone text-ink flex flex-col">
    <header class="flex items-center justify-between px-8 py-6">
      <Wordmark size="sm" />
      <nav class="flex items-center gap-6 font-mono text-xs uppercase tracking-widest text-ink-3">
        <Link v-if="canLogin" href="/login" class="hover:text-oxblood transition-colors">Sign in</Link>
        <Link v-if="canRegister" href="/register" class="hover:text-oxblood transition-colors">Create account</Link>
      </nav>
    </header>

    <main class="flex-1 flex flex-col items-center justify-center px-8 text-center max-w-3xl mx-auto">
      <Wordmark size="xl" class="mb-10" />
      <p class="font-display italic text-3xl leading-snug text-ink max-w-2xl mb-6" style="font-variation-settings: 'opsz' 144, 'SOFT' 40;">
        Every career has a thread. Apply to jobs that follow yours.
      </p>
      <p class="font-sans text-base text-ink-2 max-w-xl mb-10 leading-relaxed">
        Upload your resume. We find roles that fit the arc you're already writing &mdash; and tell you exactly why, line by line.
      </p>
      <div class="flex gap-4">
        <Link
          v-if="canRegister"
          href="/register"
          class="inline-flex items-center justify-center h-11 px-6 rounded-md bg-oxblood text-bone font-medium hover:opacity-90 transition-opacity"
        >Get started</Link>
        <Link
          v-if="canLogin"
          href="/login"
          class="inline-flex items-center justify-center h-11 px-6 rounded-md border border-[color:var(--color-hairline)] text-ink hover:bg-bone-2 transition-colors"
        >Sign in</Link>
      </div>
    </main>

    <footer class="flex items-center justify-center gap-6 py-8 font-mono text-[10px] uppercase tracking-widest text-ink-3">
      <span>Throughline</span>
      <WaypointThread :width="120" :height="6" :dot-count="5" />
      <span>{{ siteName }}</span>
    </footer>
  </div>
</template>
```

- [ ] **Step 2: Pass siteName + tagline from the controller**

Find the controller / route that renders `Welcome`. In the starter kit it's typically in `routes/web.php` as a closure returning `Inertia::render('Welcome', ...)`. Update the props payload:

```php
use App\Services\Brand\BrandConfig;
use Inertia\Inertia;

Route::get('/', function (BrandConfig $brand) {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'siteName' => $brand->name(),
        'tagline' => $brand->tagline(),
    ]);
})->name('home');
```

- [ ] **Step 3: Replace the authenticated dashboard**

Overwrite `web/throughline-web/resources/js/pages/Dashboard.vue` (the starter kit ships one — keep its `<script setup>` layout import convention; here assume `AppLayout` is the authenticated layout):

```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Head } from '@inertiajs/vue3'
import WaypointThread from '@/components/brand/WaypointThread.vue'

defineProps<{
  user: { name: string }
}>()
</script>

<template>
  <AppLayout>
    <Head title="Dashboard" />
    <div class="max-w-3xl mx-auto py-16 px-8">
      <p class="font-mono text-[11px] uppercase tracking-widest text-oxblood mb-4">Your Throughline</p>
      <h1 class="font-display text-4xl leading-tight text-ink mb-6" style="font-variation-settings: 'opsz' 144, 'SOFT' 30;">
        Welcome, <em class="italic">{{ user.name }}</em>.
      </h1>
      <p class="font-serif italic text-xl text-ink-2 leading-relaxed mb-8">
        Your feed will appear here once you've added a resume and a search profile. We'll begin with your resume.
      </p>
      <WaypointThread :width="240" :height="8" :dot-count="5" />
    </div>
  </AppLayout>
</template>
```

- [ ] **Step 4: Make sure the Dashboard route passes user data**

In `routes/web.php` (or wherever the `dashboard` route lives), make sure it shares the authenticated user:

```php
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard', [
            'user' => ['name' => auth()->user()->name],
        ]);
    })->name('dashboard');
});
```

(If the starter kit already has a dashboard route, adapt this edit to add the user prop — don't introduce a duplicate.)

- [ ] **Step 5: Brand the AppLayout chrome**

Open `web/throughline-web/resources/js/layouts/AppLayout.vue` (or the starter kit's authenticated layout). In the logo / app name slot, replace whatever is there with:

```vue
<Wordmark size="sm" />
```

Add at the top:

```ts
import Wordmark from '@/components/brand/Wordmark.vue'
```

Replace surface background classes: swap default `bg-white`/`bg-gray-*` for `bg-bone`/`bg-bone-2`, and default text classes for `text-ink`/`text-ink-2`. Nav-item hover states use `hover:text-oxblood`.

Apply the same edits to `AuthLayout.vue` (or whichever layout wraps the login/register pages).

- [ ] **Step 6: Visual check**

```bash
composer run dev
```

Verify:
- `/` shows the Throughline landing page with XL wordmark, italic Fraunces hero, oxblood CTA
- `/login` shows the branded auth layout with OAuth buttons above the form
- `/register` same
- After registering, `/dashboard` shows the branded authenticated layout with a "Welcome, Name" greeting and a waypoint thread

- [ ] **Step 7: Commit**

```bash
git add web/throughline-web/resources/js web/throughline-web/routes/web.php
git commit -m "feat(throughline-web): brand landing, dashboard, and auth layouts"
```

---

## Task 11: Deployment portability — no hardcoded host strings

**Files:**
- Create: `web/throughline-web/tests/Feature/Brand/NoHardcodedHostsTest.php`

- [ ] **Step 1: Write the test**

`web/throughline-web/tests/Feature/Brand/NoHardcodedHostsTest.php`:

```php
<?php

namespace Tests\Feature\Brand;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class NoHardcodedHostsTest extends TestCase
{
    /**
     * The spec forbids hardcoded host strings anywhere in shipped templates,
     * mail, or frontend source. Deployment must be driven by APP_URL and
     * site_settings alone. See spec §10 "Deployment Portability."
     */
    public function test_no_hardcoded_host_strings_in_shipped_sources(): void
    {
        $finder = Finder::create()
            ->in([
                base_path('resources'),
                base_path('app'),
            ])
            ->files()
            ->name(['*.php', '*.vue', '*.ts', '*.js', '*.blade.php'])
            ->notPath('vendor')
            ->notPath('node_modules');

        $forbidden = [
            'improbica.com',
            'digitalseraph.com',
            'jobber.improbica.com',
            'jobber.digitalseraph.com',
        ];

        $hits = [];
        foreach ($finder as $file) {
            $contents = $file->getContents();
            foreach ($forbidden as $needle) {
                if (str_contains($contents, $needle)) {
                    $hits[] = $file->getRelativePathname().' contains "'.$needle.'"';
                }
            }
        }

        $this->assertEmpty($hits, "Hardcoded host strings found:\n".implode("\n", $hits));
    }
}
```

- [ ] **Step 2: Run the test to confirm it passes**

```bash
cd web/throughline-web
php artisan test --compact --filter=NoHardcodedHostsTest
```

Expected: PASS (we've been disciplined throughout).

- [ ] **Step 3: Commit**

```bash
git add web/throughline-web/tests/Feature/Brand/NoHardcodedHostsTest.php
git commit -m "test(throughline-web): guard against hardcoded host strings"
```

---

## Task 12: Run the full test suite and smoke the app

**Files:** none; this task is verification.

- [ ] **Step 1: Run the full suite**

```bash
cd web/throughline-web
php artisan config:clear
php artisan test --compact
```

Expected: every test PASS. Common issues to watch for:
- Migration order — if `AddRoleToUsersTable` runs before `CreateUsersTable`, rename the file's timestamp prefix.
- `social_accounts.provider_user_id` cast — the column is a string; tests compare as string.
- If a test complains `Session has no previous URL`, the `intended()` helper is being used before any session URL is set — that's fine, `intended()` falls back to the provided default.

- [ ] **Step 2: Smoke the app in a browser**

```bash
composer run dev
```

Click through:
1. `/` — landing loads, wordmark visible, CTAs work
2. Register with email + password → redirect to `/dashboard` → first user has `role=admin` (verify with `php artisan tinker --execute 'echo \App\Models\User::first()->role;'`)
3. Sign out, sign back in → works
4. Inspect `<head>` → favicons + webmanifest present
5. OAuth buttons on login/register present but credentials not configured — expected

- [ ] **Step 3: No commit needed for verification**

If anything failed, fix it before moving to Task 13. Do not advance past Task 12 with a red suite.

---

## Task 13: Documentation — CLAUDE.md, README, and meta-repo index

**Files:**
- Create: `web/throughline-web/CLAUDE.md`
- Create: `web/throughline-web/README.md`
- Modify: `/README.md` (monorepo root)

- [ ] **Step 1: Write the sub-project CLAUDE.md**

`web/throughline-web/CLAUDE.md`:

```markdown
# Throughline — Claude Code instructions

A multi-user Laravel app that helps job seekers find roles that fit their career arc. See the design spec at `docs/superpowers/specs/2026-04-21-throughline-design.md` (in the monorepo root).

## Stack

- Laravel 13 on PHP 8.4
- Laravel Fortify (password auth) + Socialite (Google + GitHub)
- Inertia v3 + Vue 3 + TypeScript + Tailwind v4
- PostgreSQL 16 + Redis + Horizon
- Wayfinder for typed frontend-to-controller routes
- PHPUnit v12; Pint for formatting

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
createdb throughline
php artisan migrate --seed
composer run dev
```

## Key commands

- `composer run dev` — start PHP + queue + Vite concurrently
- `php artisan test --compact` — run the suite
- `php artisan test --compact --filter=SomeTest` — run one test
- `vendor/bin/pint --dirty --format agent` — format only changed files
- `php artisan tinker` — REPL in app context

## Architecture notes

- **BrandConfig** (`app/Services/Brand/BrandConfig.php`) is the single source of truth for brand-facing strings and asset paths. It reads `site_settings` first, falls back to `config/brand.php`. Never hardcode "Throughline" in templates — call `BrandConfig` or read `$siteName` props from the server.
- **SiteSettings** (`app/Services/SiteSettings.php`) is a Redis-cached singleton wrapping the `site_settings` table. Treat it as the runtime config surface admins can tune without a redeploy.
- **First-user-to-admin** — `CreateNewUser` and `SocialiteController` both promote the first registered account to `role=admin`. Safe because the count query is inside the transaction.
- **No hardcoded host strings** — enforced by `tests/Feature/Brand/NoHardcodedHostsTest.php`. The test will fail the build if `improbica.com`, `digitalseraph.com`, or related strings leak into templates or PHP source. Use `config('app.url')` or Wayfinder-generated routes.
- **OAuth** — Google and GitHub only. Redirect URIs are constructed from `APP_URL` at runtime; register the current host in each provider's console when the deployment moves.

## Adding a new brand-facing string

1. If it's user-visible and admin-tunable: add a key to `SiteSettingsSeeder` and read via `SiteSettings::get()`.
2. If it's a static default: put it in `config/brand.php`.
3. Never inline a literal "Throughline" in a Vue template.

## What's NOT here yet

Plan 1 ships the foundation only. Resumes, job ingestion, matching, the feed, application tracking, and the admin dashboard are separate plans under `docs/superpowers/plans/`.
```

- [ ] **Step 2: Write the sub-project README**

`web/throughline-web/README.md`:

```markdown
# Throughline

Multi-user web app that helps job seekers find roles fitting the career arc they're already building.

**Product stance:** radical match transparency, depth over breadth, career-narrative lens. See [`docs/superpowers/specs/2026-04-21-throughline-design.md`](../../docs/superpowers/specs/2026-04-21-throughline-design.md) for the full design.

## Requirements

- PHP 8.4
- Composer 2.x
- Node 20+
- PostgreSQL 16
- Redis

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# Provision the database
createdb throughline
php artisan migrate --seed

# Start the dev stack (PHP + queue + Vite)
composer run dev
```

Open `http://localhost:8000`.

## Environment variables

| Variable | Required | Notes |
|---|---|---|
| `APP_URL` | yes | All absolute URLs derive from this |
| `DB_*` | yes | Postgres connection |
| `REDIS_*` | yes | Redis connection |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | for Google sign-in | Register the redirect URI `{APP_URL}/auth/google/callback` |
| `GITHUB_CLIENT_ID` / `GITHUB_CLIENT_SECRET` | for GitHub sign-in | Register the redirect URI `{APP_URL}/auth/github/callback` |
| `BRAND_NAME`, `BRAND_TAGLINE` | optional | Overrides the built-in defaults; admin UI can further override via `site_settings` |

## Tests

```bash
php artisan test --compact
```

## Deployment portability

No host strings are ever hardcoded. To move to a new domain:

1. Update `APP_URL` in the deploy environment.
2. Add the new host to the Google + GitHub OAuth consoles.
3. Deploy — no code changes needed.

## Status

Foundation only. See `docs/superpowers/plans/` for the full plan roadmap.
```

- [ ] **Step 3: Update the monorepo root README**

Edit `/README.md`. Update the Projects table to include Throughline. The final table should be:

```markdown
## Projects

| Path | Description |
| --- | --- |
| [`cli/ds-cli`](cli/ds-cli) | Cross-platform CLI tool for DigitalSeraph-Meta (Bash + PowerShell) |
| [`web/improbica-web`](web/improbica-web) | Laravel 13 + Vue 3 app for Improbica (improbica.com) |
| [`web/throughline-web`](web/throughline-web) | Throughline — Laravel 13 + Vue 3 job-discovery app |
```

- [ ] **Step 4: Commit**

```bash
git add web/throughline-web/CLAUDE.md web/throughline-web/README.md README.md
git commit -m "docs(throughline-web): CLAUDE.md + README + monorepo project table entry"
```

- [ ] **Step 5: Final smoke**

```bash
cd web/throughline-web
php artisan test --compact
```

Expected: green. You're done with Plan 1.

---

## Plan-1 Exit Criteria

A reviewer should be able to verify all of the following in < 10 minutes:

- [ ] `composer run dev` boots cleanly
- [ ] `php artisan test --compact` is green
- [ ] `/` shows the Throughline landing page with the wordmark + waypoint thread
- [ ] Register with email + password → first user has `role='admin'` in the DB
- [ ] Register a second user → stays `role='user'`
- [ ] `/login` and `/register` show Google + GitHub buttons
- [ ] `/dashboard` renders with branded layout after sign-in
- [ ] `tests/Feature/Brand/NoHardcodedHostsTest.php` passes
- [ ] `php artisan config:show brand.name` prints `Throughline`
- [ ] Admin can already tune `site_name` / `site_tagline` in the DB (`site_settings` table) and the app reads the new value on the next request (admin UI to edit these comes in Plan 6)
- [ ] The monorepo `README.md` lists `web/throughline-web`

After Plan 1 is merged, the next plan ("Plan 2 — Resume module") will build on this foundation: `AiClient`, pgvector extension, the `resumes` table + variants UI, parse + embed jobs.
