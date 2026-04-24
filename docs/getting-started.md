# Getting started

Goal: clone the repo and land on a working local Throughline with a ranked job feed. Target time: 20 minutes from a clean machine, faster if you already have the toolchain.

## Prerequisites

| Tool | Version | Why |
|---|---|---|
| PHP | 8.4 (Herd bundled works; must have `pdo_pgsql`) | Runtime |
| Composer | 2.x | PHP deps |
| Node | 20+ | Frontend build + Playwright |
| npm | bundled with Node | Frontend installs |
| Docker Desktop | 4.30+ | Local Postgres + Redis |
| Git | any recent | Clone |
| Poppler (`pdftotext`) | any | PDF resume text extraction. Windows: `winget install poppler`. macOS: `brew install poppler`. Linux: `apt install poppler-utils`. |

Optional but recommended:
- [GitHub CLI (`gh`)](https://cli.github.com/) — for PR/check operations if you're contributing.

## Before you start

Before running `composer run dev`, you'll need credentials for **at least**:

- **OpenAI API key** — used for resume + job embeddings.
- **Anthropic API key** — used for resume parsing and stage-2 rerank.

Without these the app runs, but resumes get stuck at parse and the feed will be empty of stage-2 rationale. The [external accounts guide](external-accounts-setup.md) walks you through getting every key Throughline uses.

## Clone and install

```bash
git clone https://github.com/digitalseraph/throughline.git
cd throughline
composer install
npm install
cp .env.example .env
php artisan key:generate
```

## Start the Docker stack

```bash
docker compose up -d
docker compose ps
```

Expected: `throughline-postgres` (with pgvector) and `throughline-redis` both `healthy`. If port 5432 is in use on your host, stop the conflicting Postgres instance — the Docker container owns that port in dev.

## Run migrations and seed

```bash
php artisan migrate --seed
```

You should see a stack of migrations run, and three seeders (`SiteSettingsSeeder`, `JobSourcesSeeder`, and any default-user seeder) populate rows.

**Windows note:** if your Herd `php` shim is missing `pdo_pgsql`, use the explicit Herd binary:

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan migrate --seed
```

## Add your API keys

Edit `.env`:

```env
OPENAI_API_KEY=sk-...your-key...
ANTHROPIC_API_KEY=sk-ant-...your-key...
```

See the [external accounts guide](external-accounts-setup.md) if you haven't obtained them yet.

## Start the dev stack

```bash
composer run dev
```

This concurrently starts:
- `php artisan serve` on http://localhost:8000
- Vite dev server on 5173 (auto-HMR)
- Laravel queue listener (processes all the queued jobs — parse, embed, fan-out, rerank, etc.)
- Laravel Reverb WebSocket server for real-time updates

Open http://localhost:8000. You'll see the landing page.

## First run: create an account

1. Click **Register** (or use the Google / GitHub OAuth buttons if you've configured those — see [external accounts](external-accounts-setup.md)).
2. Fill in name / email / password. The first user on a fresh install is automatically promoted to **admin** — this is important for seeing the admin UI.
3. You're redirected to the dashboard.

## Sanity check: full flow

1. Navigate to **Resumes** in the left nav. Upload a PDF or DOCX of your resume.
2. Wait a moment — the page surfaces "Ready to match" when parsing + embedding complete (Reverb pushes the update; if you're running without Reverb for some reason, a 30-second fallback poll also catches it).
3. Navigate to **Search profiles** → **New profile**. Pick the resume, give the profile a name, set `remote_mode`, save.
4. Navigate to **Admin → Sources** (visible because you're the first admin). Click "Fetch now" on one of the seeded Greenhouse boards (Stripe is safe). Wait ~30 seconds for the ingestion pipeline to pull jobs.
5. Go back to your profile's feed via the **View feed** link. You should see ranked job cards with Claude-generated rationale, fit scores, and gap chips.
6. Click **Save** on a card. Open **Applications** in the nav. The saved job appears in the Saved column of the kanban.

If everything works, you're all set.

## Running tests

### PHP suite

```bash
php artisan test --compact
```

Should report ~232 passing, 1 skipped (the upstream-drift test, which only runs when `THROUGHLINE_UPSTREAM_DRIFT_TEST=1` is set).

### Frontend lint + format

```bash
npm run lint
npm run format
```

### Playwright E2E

```bash
npm run test:e2e
```

The spec drives the full happy path: register → upload resume → create profile → view feed with stage-2 assertions → save to kanban → admin dashboard smoke.

## What if something doesn't work?

- **`docker compose up -d` fails on port 5432** — you have a host Postgres running. Stop it, or change the port mapping in `compose.yaml` (and update `.env` DB_PORT accordingly).
- **Migrations complain about `pdo_pgsql` missing** — use the explicit Herd PHP path shown above.
- **Parsing never completes** — check `OPENAI_API_KEY` and `ANTHROPIC_API_KEY` in `.env`. Check the queue listener is running (`composer run dev` starts it).
- **Feed empty** — confirm you seeded `job_sources`, fetched at least one source manually via the admin UI, and waited for the embed + fan-out queue jobs to complete.
- **Reverb not connecting** — verify `php artisan reverb:start` is running (part of `composer run dev`), and that `.env` has `BROADCAST_CONNECTION=reverb` plus the `REVERB_*` and `VITE_REVERB_*` variables.
- **Windows + Herd + Reverb** — Reverb depends on PHP extensions that may not be present in the Windows Herd build. If `reverb:start` fails, drop `BROADCAST_CONNECTION=log` in `.env` and accept polling fallback.

More troubleshooting lives in the [operations doc](operations.md) runbook section.

## Next steps

- Read the [user guide](user-guide.md) to learn what every feature does.
- Read the [admin guide](admin-guide.md) to see what `/admin` gives you.
- For full functionality (OAuth sign-in, all 8 ingestion drivers, email reminders), walk through the [external accounts guide](external-accounts-setup.md).
