# Throughline

Multi-user web app that helps job seekers find roles fitting the career arc they're already building.

**Product stance:** radical match transparency, depth over breadth, career-narrative lens. See [`docs/superpowers/specs/2026-04-21-throughline-design.md`](../../docs/superpowers/specs/2026-04-21-throughline-design.md) for the full design.

## Requirements

- PHP 8.4 (8.2+ would work, but the dev environment expects 8.4)
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

Open http://localhost:8000.

## Environment variables

| Variable | Required | Notes |
|---|---|---|
| `APP_URL` | yes | All absolute URLs derive from this |
| `DB_*` | yes | Postgres connection |
| `REDIS_*` | yes | Redis connection |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | for Google sign-in | Register the redirect URI `{APP_URL}/auth/google/callback` |
| `GITHUB_CLIENT_ID` / `GITHUB_CLIENT_SECRET` | for GitHub sign-in | Register the redirect URI `{APP_URL}/auth/github/callback` |
| `BRAND_NAME`, `BRAND_TAGLINE` | optional | Override the built-in defaults; admin UI can further override via `site_settings` |

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

Foundation only. See `../../docs/superpowers/plans/` for the full plan roadmap.
