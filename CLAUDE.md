# Throughline — Claude Code instructions

A multi-user Laravel app that helps job seekers find roles that fit their career arc. See the design spec at [`docs/superpowers/specs/2026-04-21-throughline-design.md`](docs/superpowers/specs/2026-04-21-throughline-design.md).

This repository was extracted from the `digitalseraph-meta` monorepo after Plan 1 (Foundation) completed. Historical plan artifacts under `docs/superpowers/` reference the pre-extraction layout (`web/throughline-web/...`); see the layout notes at the top of each document.

## Stack

- Laravel 12 on PHP 8.4 (starter kit shipped with Laravel 12; plan originally assumed 13)
- Laravel Socialite (Google + GitHub) — no Fortify in this install
- Inertia v2 + Vue 3 + TypeScript + Tailwind v3 (not v4)
- Ziggy for the JS `route()` helper
- PostgreSQL 16 + Redis + Horizon (Horizon can't `horizon:work` on Windows — Linux-only via `ext-pcntl`)
- PHPUnit v11.5; Pint for formatting

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

Dev server starts at http://localhost:8000. Vite runs on 5173.

## Dev environment quirks (Windows)

- The `php` on PATH (Herd shim or equivalent) may lack `pdo_pgsql`. Use the explicit Herd PHP for all artisan against Postgres: `/c/Users/david/.config/herd/bin/php84/php.exe artisan …`
- Horizon's `ext-pcntl` is Linux-only. The package is installed but `horizon:work` won't run locally on Windows. Use `queue:listen` for dev; Horizon kicks in in production.

## Key commands

- `composer run dev` — start PHP + queue + Vite concurrently
- `php artisan test --compact` — run the suite
- `php artisan test --filter=SomeTest` — run one test
- `vendor/bin/pint --dirty` — format only changed files
- `php artisan tinker` — REPL in app context

## Architecture notes

- **BrandConfig** (`app/Services/Brand/BrandConfig.php`) is the single source of truth for brand-facing strings and asset paths. It reads `site_settings` first, falls back to `config/brand.php`. Never hardcode "Throughline" in templates — call `BrandConfig` or read `siteName`/`tagline` props from the server.
- **SiteSettings** (`app/Services/SiteSettings.php`) is a cached singleton wrapping the `site_settings` table. Treat it as the runtime config surface admins can tune without a redeploy.
- **First-user-to-admin** — `RegisteredUserController::store()` sets `role = User::count() === 0 ? 'admin' : 'user'`. The `SocialiteController` callback does the same when creating a user via OAuth.
- **No hardcoded host strings** — enforced by `tests/Feature/Brand/NoHardcodedHostsTest.php`. The test will fail CI if `improbica.com`, `digitalseraph.com`, or related strings leak into templates or PHP source. Use `config('app.url')` or Ziggy-generated route helpers.
- **OAuth** — Google and GitHub only. Redirect URIs constructed from `APP_URL` at runtime; register the current host in each provider's console when the deployment moves. Routes are defined in `routes/auth.php`: `auth.social.redirect` (guest-only) and `auth.social.callback` (not gated by guest so it can be used for account linking by an already-authenticated user).

## Adding a new brand-facing string

1. If it's user-visible and admin-tunable: add a key to `SiteSettingsSeeder` and read via `SiteSettings::get()`.
2. If it's a static default: put it in `config/brand.php`.
3. Never inline a literal "Throughline" in a Vue template — the `NoHardcodedHostsTest` currently only guards hosts but a brand-string test should be added in a future plan.

## What's NOT here yet

Plan 1 ships the foundation only. Resumes, job ingestion, matching, the feed, application tracking, and the admin dashboard are separate plans under `docs/superpowers/plans/`.
