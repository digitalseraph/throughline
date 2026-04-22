# Throughline — Claude Code instructions

A multi-user Laravel app that helps job seekers find roles that fit their career arc. See the design spec at [`docs/superpowers/specs/2026-04-21-throughline-design.md`](docs/superpowers/specs/2026-04-21-throughline-design.md) and the Plan 2 design at [`docs/superpowers/specs/2026-04-21-throughline-plan-2-design.md`](docs/superpowers/specs/2026-04-21-throughline-plan-2-design.md).

This repository was extracted from the `digitalseraph-meta` monorepo after Plan 1 (Foundation) completed. Historical plan artifacts under `docs/superpowers/` reference the pre-extraction layout (`web/throughline-web/...`); see the layout notes at the top of each document.

## Stack

- Laravel 12 on PHP 8.4
- Laravel Socialite (Google + GitHub) — no Fortify in this install
- Inertia v2 + Vue 3 + TypeScript + Tailwind v3
- PostgreSQL 16 + pgvector + Redis via Docker Compose (dev) / managed services (prod)
- Horizon for queues (prod); `queue:listen` for Windows dev (`ext-pcntl` is Linux-only)
- OpenAI `text-embedding-3-small` for embeddings, Anthropic Claude Sonnet 4.6 for resume parsing
- Poppler (`pdftotext`) + `phpoffice/phpword` for resume text extraction
- PHPUnit v11.5; Playwright for E2E; Pint for formatting

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
docker compose up -d
php artisan migrate --seed
composer run dev
```

Dev server: http://localhost:8000. Vite: 5173. Postgres: 5432 (Docker). Redis: 6379 (Docker).

## Dev environment quirks (Windows)

- The `php` on PATH (Herd shim or equivalent) may lack `pdo_pgsql`. Use the explicit Herd PHP for all artisan against Postgres: `/c/Users/david/.config/herd/bin/php84/php.exe artisan …`
- Horizon's `ext-pcntl` is Linux-only. The package is installed but `horizon:work` won't run locally on Windows. Use `queue:listen` for dev (part of `composer run dev`); Horizon kicks in in production.
- Poppler's `pdftotext.exe` must be on PATH for resume PDFs to parse locally. `winget install poppler` works.
- Playwright E2E tests require a Chromium binary. Run `npm run test:e2e:install` once to cache it. The E2E suite may not run in restricted CI environments without a display; the PHP suite is the primary gate.

## Key commands

- `docker compose up -d` — start Postgres + Redis containers (required before `composer run dev`)
- `docker compose logs -f postgres` — tail Postgres logs
- `composer run dev` — start PHP + queue listener + Vite concurrently
- `php artisan test --compact` — run the PHP test suite
- `php artisan test --filter=SomeTest` — run one test
- `npm run test:e2e` — run Playwright E2E smoke against the testing environment
- `npm run test:e2e:install` — install Playwright browser binaries (run once)
- `vendor/bin/pint --dirty` — format only changed files
- `php artisan throughline:rerank-now {profile-id}` — synchronously rerank a profile's top-K stale matches
- `php artisan tinker` — REPL in app context

## Architecture notes

- **`AiClient`** (`app/Services/Ai/AiClient.php`) is the single entry point for every OpenAI and Anthropic call. Never import the provider SDKs directly. `forUser($user)` sets attribution; `forUser(null)` marks the call as system-attributed (ingestion). Cost-ceiling enforcement, rate limiting, and `ai_usage_events` writes all live here. In the `testing` environment `TestingServiceProvider` replaces it with a fake that returns deterministic vectors (`array_fill(0, 1536, 0.5)`) and stub JSON — no real API calls in tests.
- **`JobSourceDriver`** (`app/Contracts/Jobs/JobSourceDriver.php`) + `DriverRegistry` (`app/Services/Jobs/DriverRegistry.php`). Drivers know nothing about matching. `config/throughline.php` maps `source_type → driver class`. Driver upstream hostnames (`boards-api.greenhouse.io`, `api.lever.co`, `remoteok.com/api`) must not appear outside driver classes, `config/services.php`, or tests — enforced by `DriverHostsTest`.
- **Per-entity vectors** — resumes and canonical_jobs each carry their own `embedding vector(1536)`. No shared vector table. Eloquent can't cast pgvector natively; reads and writes use raw `DB::statement`.
- **`BrandConfig`** (`app/Services/Brand/BrandConfig.php`) is the single source of truth for brand-facing strings and asset paths. It reads `site_settings` first, falls back to `config/brand.php`. Never hardcode "Throughline" in templates — call `BrandConfig` or read `siteName`/`tagline` props from the server.
- **`SiteSettings`** (`app/Services/SiteSettings.php`) is a cached Redis singleton wrapping the `site_settings` table. Plan 2 additions: `stage1_score_floor`, `cost_ceiling_per_user_per_day_usd`, `openai_embeddings_model`, `anthropic_parse_model`.
- **First-user-to-admin** — `RegisteredUserController::store()` sets `role = User::count() === 0 ? 'admin' : 'user'`. The `SocialiteController` callback does the same when creating a user via OAuth.
- **No hardcoded host strings** — enforced by `tests/Feature/Brand/NoHardcodedHostsTest.php` (brand hosts) and `tests/Feature/Brand/DriverHostsTest.php` (driver upstream hosts). Both tests will fail CI if forbidden strings leak. Use `config('app.url')` or Ziggy-generated route helpers.
- **OAuth** — Google and GitHub only. Redirect URIs constructed from `APP_URL` at runtime. Routes in `routes/auth.php`: `auth.social.redirect` (guest-only) and `auth.social.callback` (not gated by guest so it can be used for account linking).
- **Test-only routes** — `routes/testing.php` is conditionally loaded by `bootstrap/app.php` when `APP_ENV=testing`. It exposes `POST /testing/seed-jobs`, `POST /testing/run-matching/{profileId}`, and `POST /testing/apply-rerank/{profileId}` for Playwright E2E use. These routes are never present in production.
- **Stage-2 rerank** — `AiClient::rerank()` produces fit score + rationale + gaps + narrative via Claude Sonnet with all-or-throw semantics. `RerankShortlist` is the only writer to `job_matches.stage2_*` columns and is dispatchable sync (feed controller) or async (nightly refresh). Failures write a `'rerank unavailable'` sentinel so the stale-row query doesn't loop.
- **Nightly rerank** — `NightlyRerankRefresh` runs at the UTC hour set by `site_settings.nightly_rerank_hour_utc` (default 04:00) and reranks the top-50 per active profile with a linked resume.
- **Feed ordering** — `FeedController` orders by `stage2_score DESC NULLS LAST, stage1_score DESC` so fresh-reranked matches rise above never-reranked ones. `?raw=1` bypasses rerank entirely for debugging.
- **Drivers (Plan 3)** — `WorkableDriver` + `AshbyDriver` extend the ATS shape from Plan 2. `AdzunaDriver` introduces the paginated-aggregator shape; credentials required via `ADZUNA_APP_ID` + `ADZUNA_APP_KEY`.

## Adding a new brand-facing string

1. If it's user-visible and admin-tunable: add a key to `SiteSettingsSeeder` and read via `SiteSettings::get()`.
2. If it's a static default: put it in `config/brand.php`.
3. Never inline a literal "Throughline" in a Vue template — the `NoHardcodedHostsTest` currently only guards hosts but a brand-string test should be added in a future plan.

## What's here now (post Plan 3)

Resume upload with parse + embed, search-profile CRUD, six job-source drivers (Greenhouse, Lever, RemoteOK, Workable, Ashby, Adzuna), stage-1 cosine matching fan-out, stage-2 Claude rerank (`AiClient::rerank()`) producing fit score + narrative + rationale + gap chips, nightly rerank refresh (`NightlyRerankRefresh`), ranked feed ordering by `stage2_score DESC NULLS LAST, stage1_score DESC`, `?raw=1` debug bypass, `throughline:rerank-now` artisan command, admin CRUD for job sources with fetch-now. No application tracking, no analytics — those ship in Plan 4+.
