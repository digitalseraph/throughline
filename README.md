# Throughline

Multi-user web app that helps job seekers find roles fitting the career arc they're already building.

**Product stance:** radical match transparency, depth over breadth, career-narrative lens. See [`docs/superpowers/specs/2026-04-21-throughline-design.md`](docs/superpowers/specs/2026-04-21-throughline-design.md) for the full design.

## Requirements

- PHP 8.4
- Composer 2.x
- Node 20+
- Docker (for Postgres 16 + pgvector + Redis)

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# Start Postgres + Redis via Docker
docker compose up -d

# Run migrations and seed data
php artisan migrate --seed

# Start the dev stack (PHP + queue listener + Vite)
composer run dev
```

Open http://localhost:8000.

> **Windows note:** if `php` on your PATH lacks `pdo_pgsql`, use the explicit Herd binary:
> `/c/Users/david/.config/herd/bin/php84/php.exe artisan migrate --seed`

## Environment variables

| Variable | Required | Notes |
|---|---|---|
| `APP_URL` | yes | All absolute URLs derive from this |
| `DB_*` | yes | Postgres connection (default: Docker container on 5432) |
| `REDIS_*` | yes | Redis connection (default: Docker container on 6379) |
| `OPENAI_API_KEY` | yes | Used by `AiClient` for embeddings |
| `ANTHROPIC_API_KEY` | yes | Used by `AiClient` for resume parsing |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | for Google sign-in | Register the redirect URI `{APP_URL}/auth/google/callback` |
| `GITHUB_CLIENT_ID` / `GITHUB_CLIENT_SECRET` | for GitHub sign-in | Register the redirect URI `{APP_URL}/auth/github/callback` |
| `BRAND_NAME`, `BRAND_TAGLINE` | optional | Override the built-in defaults; admin UI can further override via `site_settings` |

## Tests

```bash
# PHP test suite
php artisan test --compact
```

## Running the E2E smoke

```bash
# Install Playwright browser binaries (once)
npm run test:e2e:install

# Run against a testing-env server (spun up automatically by Playwright)
npm run test:e2e
```

The E2E spec drives the full happy path: register → upload resume → create search profile → seed jobs → recompute stage-1 matches → view ranked feed. It relies on `TestingServiceProvider` (fake `AiClient` returning deterministic vectors) and test-only routes exposed only when `APP_ENV=testing`.

> **Windows note:** Playwright requires a Chromium binary and a display. In headless-only CI containers it should work fine; interactive Windows desktops may need the binary pre-installed via `npm run test:e2e:install`.

## Deployment portability

No host strings are ever hardcoded. To move to a new domain:

1. Update `APP_URL` in the deploy environment.
2. Add the new host to the Google + GitHub OAuth consoles.
3. Deploy — no code changes needed.

### Nightly upstream drift test

`tests/Feature/Jobs/JobSourcesUpstreamDriftTest.php` hits the real Greenhouse
API against `stripe`'s public board. It is skipped by default (PR CI never runs it).
Enable it nightly by setting `THROUGHLINE_UPSTREAM_DRIFT_TEST=1` in the scheduled
CI environment. Treat a failure as a signal that a driver needs updating.

## Status

Plan 2 complete. See [`docs/superpowers/plans/`](docs/superpowers/plans/) for the full plan roadmap. Plan 3 adds stage-2 reranking and rationale generation.
