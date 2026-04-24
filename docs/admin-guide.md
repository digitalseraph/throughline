# Admin guide

Everything the admin surface gives you at `/admin`.

## Table of contents

- [Getting admin access](#getting-admin-access)
- [Overview](#overview)
- [Users](#users)
- [Impersonation](#impersonation)
- [Jobs](#jobs)
- [Sources](#sources)
- [Analytics](#analytics)
- [Costs](#costs)
- [Settings](#settings)
- [Audit log](#audit-log)
- [Error log](#error-log)
- [Horizon (queue dashboard)](#horizon-queue-dashboard)
- [CLI commands for admins](#cli-commands-for-admins)

---

## Getting admin access

Three paths:

1. **First user on a fresh install.** The first user to register (via email/password OR via Google/GitHub OAuth) is automatically promoted to `role = 'admin'`. No configuration needed. If that's you, you see an "Admin" link in the left nav immediately after sign-up.

2. **Promoted via the admin UI.** Any existing admin can promote another user. Go to **Admin → Users**, click a user, click **Set role to admin**. An audit log entry is written.

3. **Promoted via `tinker`.** On a running install:
   ```bash
   php artisan tinker
   >>> User::where('email', 'you@example.com')->update(['role' => 'admin']);
   ```
   Works but skips the audit log — only use when you can't reach the UI.

The admin nav link shows up automatically once `role = 'admin'` is persisted. Refresh your browser if it doesn't appear.

### What happens if I lose admin access?

The app guards against self-locking:

- `UserController::update` refuses to modify the currently-authenticated admin's own account (422). You can't accidentally set your own role to `user` or deactivate yourself.
- `EnsureAdmin` middleware rejects both non-admins AND deactivated admins — a deactivated admin can't bypass the gate.

If you end up with no active admins (e.g., the only admin's password is lost), the `tinker` path is your escape hatch.

---

## Overview

`/admin` — the landing page.

A grid of summary cards:

- **Users** — total count + 7-day active count (users whose `last_login_at` is within 7 days)
- **Jobs** — total `canonical_jobs` + how many landed today (`created_at` after start-of-day)
- **Matches** — total `job_matches` rows
- **AI spend** — today's and this month's sum of `ai_usage_events.estimated_cost_usd`

Below the cards:

- **Applications by status** — pill badges showing the count of applications across all users in each of the 8 statuses.
- **Source health** — table of all `job_sources` with columns: name, last_fetched_at, failure count, status (OK / Error / Paused). Paused sources (auto-disabled after 3 consecutive failures) highlight red.

The page is a pure read view — no actions. Use it to understand the current state at a glance.

---

## Users

`/admin/users` — list and manage user accounts.

### List view

Paginated table (25/page). Columns:

- **Email** (click to open detail)
- **Name**
- **Role** (user / admin)
- **Resumes** (count via `withCount`)
- **Apps** (applications count)
- **Last login**
- **Status** (Active / Deactivated)

Search box at the top filters by email OR name (case-insensitive `ILIKE`). The query persists via `?q=...` so you can bookmark filtered views.

### Detail view

`/admin/users/{id}` — full view of one user.

Shows:

- Email, name, role
- Last login date
- Resume count, application count (clickable — jumps to that user's resumes via impersonation, or the global Jobs filtered by that user's matches)

Actions:

- **Set role to admin/user** — toggles. Writes a `user.role_changed` audit entry. Can't target yourself.
- **Impersonate** (button only visible for non-admin users) — starts impersonation (see below).
- **Deactivate / Reactivate** — toggles `deactivated_at`. Deactivated users can't log in OR be impersonated. Writes an audit entry. Can't deactivate yourself.

All actions have a JavaScript `confirm()` prompt.

---

## Impersonation

Impersonation lets you sign in AS another user to debug their view. It's a one-level operation — you can't nest impersonations (if you try, `EnsureAdmin` blocks it).

### Starting

On `/admin/users/{id}`, click **Impersonate**. After confirmation:

- Your session's `original_user_id` is stashed with your admin id.
- `Auth::login($target)` swaps you into the target user.
- An audit log entry (`user.impersonation_started`) is written, attributed to you.
- You're redirected to `/dashboard` as the target.

### The persistent banner

Across every page while impersonating, a red bar at the top says:

> Impersonating `target@example.com` (as `you@example.com`). [Stop impersonating]

This is the `<ImpersonationBanner>` component. It's surfaced via an Inertia shared prop (`impersonation` in `HandleInertiaRequests`) so it shows on any page without the page needing to know about it.

### What can't be impersonated

- **Another admin.** `ImpersonationController::start` returns 403 if the target's role is `admin`. No audit entry is written on this 403 path — the block happens before side effects.
- **Yourself.** Same guard.
- **A deactivated user.** The middleware blocks the target.
- **While already impersonating.** `session()->has('original_user_id')` check returns 403.

### Stopping

Click **Stop impersonating** in the banner. This hits `POST /impersonate/stop` — note: NOT in the `/admin` namespace, because while impersonating you're not an admin.

The stop controller:

- Reads `original_user_id` from session.
- `Auth::login` back as your admin account.
- Clears the session key.
- Regenerates the session.
- Writes an audit log entry (`user.impersonation_stopped`) attributed to you as admin.
- Redirects to `/admin/users/{target-id}` so you land back in context.

### Caveats

- **Anything you do while impersonating is attributed to the target user in their application/resume/profile data.** Don't upload a resume while impersonating unless you mean to give that user a resume.
- **Audit log entries for your admin actions are attributed to you as admin**, not to the impersonated target. The impersonation start/stop rows bracket any admin activity in between, so the trail is complete.
- **Impersonation does not copy the target's linked social accounts.** If they log in via Google and you impersonate, you can't use their Google OAuth — you're using their account with your own session.

---

## Jobs

`/admin/jobs` — read-only browser over `canonical_jobs`.

### List view

Paginated 50/page. Columns: source, company, title, location, remote mode, posted date, closed status.

Filters (all query-string persistent):

- **Source** dropdown — one of the 8 source types (greenhouse/lever/remoteok/workable/ashby/adzuna/remotive/usajobs) or "all".
- **Company** text field — `ILIKE '%text%'` match.
- **Has embedding** checkbox — filters to jobs with non-null `embedding`.
- **Closed only** checkbox — shows only jobs with non-null `closed_at`.

### Detail view

`/admin/jobs/{id}` — full job record.

Sections:

- **Job metadata** — all the canonical fields.
- **Raw payload** — collapsible. Shows the raw JSON from the upstream API (`canonical_jobs.raw_payload`). Useful for debugging driver normalization issues.
- **Matches** — every `job_matches` row referencing this job, with the user's email, stage-1 and stage-2 scores, match date.
- **Applications** — every `applications` row saved from this job, with user email, current status, save date.

This page is the fastest way to answer "which users have interacted with this job?" and "does the normalization look right vs the upstream?".

---

## Sources

`/admin/job-sources` — the job-source registry. Plan 2 added this; Plans 3–5 refined it.

### List view

Every row in `job_sources` with columns:

- Display name
- Source type (driver slug)
- Identifier (Greenhouse board slug / Lever company / null for aggregators)
- Fetch cadence in minutes
- Last fetched timestamp
- Status: **OK** (recent fetch, no errors), **Error** (has a `last_error`), **Paused** (auto-disabled after 3 consecutive failures)

Actions per row:

- **Fetch now** — immediately dispatches a `FetchJobSource` job, bypassing the cadence check. Use this after creating a new source, or to retry after a failure.
- **Delete** — removes the source. Existing `canonical_jobs` rows from this source are kept (no cascade).

### Creating a new source

Click **New source** at the top. Form:

- **Source type** — dropdown of registered drivers (from `config/throughline.php`).
- **Identifier** — the thing that identifies this source to the driver. Examples:
  - `greenhouse/stripe` — Greenhouse board slug
  - `lever/netflix` — Lever company slug
  - `workable/shopify` — Workable company slug
  - `ashby/vercel` — Ashby company slug
  - `remoteok` + `null` — RemoteOK is single-feed, identifier is meaningless
  - `remotive` + `null` — same
  - `usajobs` + `null` — same
  - `adzuna/us` — country code (us, gb, au, ca, etc.)
- **Display name** — what shows up in the list + analytics.
- **Fetch cadence minutes** — minimum 5, maximum 1440. How often the scheduler dispatches a fetch. Lower = more frequent = more API usage.
- **Enabled** — checkbox. Unchecked sources are skipped by the scheduler but kept in the registry.

The form validates the identifier against the driver's `validateIdentifier()` method — Greenhouse requires non-empty, RemoteOK/Remotive/USAJobs accept null, etc.

### Editing

Click the display name on the list. Same form. You can also see the `last_error` rendered above the form if present.

### Adzuna country config

Adzuna stores its country in `config_json.country`. When creating an Adzuna source via the UI, the form currently doesn't expose `config_json` directly — you'd need to edit the seeded row or add it via tinker:

```bash
php artisan tinker
>>> JobSource::where('source_type', 'adzuna')->where('identifier', 'gb')->update(['config_json' => ['country' => 'gb']]);
```

(A future version may expose the `config_json` editor in the UI.)

---

## Analytics

`/admin/analytics` — 5 Chart.js charts.

### Daily ingestion (30 days)

Line chart with one dataset per source. Shows how many canonical_jobs were inserted per day per source. Spikes correspond to fresh source boards coming online or big ATS batches. Gaps mean the source wasn't being fetched (paused or disabled).

### Daily matches (30 days)

Line chart, single dataset. How many `job_matches` rows were created per day across all users. Trends with resume + job embedding throughput.

### Rerank calls (30 days)

Line chart, single dataset. Count of `ai_usage_events` rows where `operation='rerank'`. One row per Claude batch — so a user opening a feed that triggers two batches counts as two.

### AI spend (30 days, USD)

Line chart, single dataset. `SUM(estimated_cost_usd)` per day. Both embed and rerank costs combined.

If you want per-user AI spend, the underlying `Analytics::dailyAiCost($days, $userId)` method supports it — but v1's UI doesn't expose a user-filter selector. Add via tinker or a future plan.

### Application funnel

Bar chart showing count per status across all users: Saved / Applied / Phone screen / Technical / Onsite / Offer / Rejected / Withdrawn. Gives you a sense of how the user base is moving through the pipeline.

### Data freshness

Queries hit raw tables directly — no caching. For realistic v1 data volumes this is fine; page loads under a second. If it slows, a daily-aggregates table is the future-plan escape hatch.

---

## Costs

`/admin/costs` — tracks the total cost of running Throughline: fixed subscription line items (Forge, DO droplet, Postgres, Redis, Spaces, Cloudflare, Postmark, Sentry) plus live AI spend pulled from `ai_usage_events`.

### Header cards

- **This month total** — fixed subscriptions + AI month-to-date. Highlighted in oxblood when over threshold.
- **Fixed subscriptions** — sum of active line items, annuals normalized to `/12`.
- **AI month-to-date** — sum of `estimated_cost_usd` on `ai_usage_events` since the 1st of the current month.
- **AI rolling 30d** — trailing 30-day window for planning.

### Line items

CRUD table of fixed subscriptions. Fields: name, category, amount (USD), billing cycle (monthly / annual), notes, active flag. Click **+ Add line item** or **Edit** / **Delete** per row. Every create/update/delete writes an entry to the admin audit log (`cost.created`, `cost.updated`, `cost.deleted`).

Seeded defaults on a fresh install match the documented stack: Forge $12, DO droplet $18, DO Postgres $15, DO Redis $15, DO Spaces $5, Cloudflare $0, Postmark $15, Sentry $0.

### Monthly history chart

Line chart over the last 12 months showing three series: Fixed, AI, Total. Populated from `infrastructure_cost_snapshots`, which is written on the 1st of each month by `costs:close-month` (scheduled in `routes/console.php`). Empty until the first snapshot is recorded.

### Alert threshold

Admins get one email alert per calendar month if the current-month total crosses the configured threshold. Default is $100; set to 0 to disable. The dedupe key is `site_settings.cost_alert_last_notified_month` — it resets on the 1st via the close-month command.

Alerts only send to admins whose `notification_email_enabled` is true (same flag that gates reminder emails). The check runs daily at 01:00 UTC (`costs:check-alert`).

Force a close/check manually if needed:

```bash
php artisan costs:close-month --month=2026-03   # backfill a specific month
php artisan costs:check-alert                    # run the threshold check now
```

---

## Settings

`/admin/settings` — key/value editor over `site_settings` + read-only view of environment-sourced secrets.

### Editable settings (from the `site_settings` table)

Grouped into two sections:

**General** (everything not prefixed with `brand_`):

- `signups_enabled` — when `false`, the public `/register` route redirects to login with "New registrations are currently closed", `POST /register` is blocked, and the OAuth callback refuses to create a new user (existing accounts can still sign in). The Welcome / About / Help pages hide the "Create account" CTAs while it's off. Default `true`.
- `maintenance_mode` — when `true`, the site serves a static "We're tuning things up" page (HTTP 503) for everyone except admin users. Login + logout + the `/up` health endpoint stay open so admins can sign in to lift the lockout. Default `false`.
- `stage1_score_floor` — minimum cosine similarity to produce a match row (default `0.2`).
- `cost_ceiling_per_user_per_day_usd` — per-user daily spend ceiling (default `5.00`).
- `openai_embeddings_model` — default `text-embedding-3-small`.
- `anthropic_parse_model` — default `claude-sonnet-4-6`.
- `anthropic_rerank_model` — default `claude-sonnet-4-6`.
- `rerank_enabled` — kill-switch for stage-2 rerank (`true`/`false`).
- `rerank_shortlist_size` — top-K to rerank on feed view (default `20`).
- `rerank_max_batch_size` — max jobs per Claude rerank call (default `20`).
- `stage2_ttl_hours` — staleness threshold (default `24`).
- `nightly_rerank_hour_utc` — UTC hour to run nightly refresh (default `4`).
- `reminder_scan_hour_utc` — UTC hour for reminder scan (default `9`).
- `nav_reminder_badge_ttl_seconds` — Redis cache TTL for the nav badge count (default `60`).

**Theming** (prefix `brand_`):

- `brand_wordmark_url_override` — URL to a custom SVG wordmark. Leave empty for default.
- `brand_favicon_url_override` — URL to custom favicon.
- `brand_color_primary_override` — hex color for ink token (e.g. `#1a1410`).
- `brand_color_oxblood_override` — hex color for oxblood accent.
- `brand_font_sans_override` — CSS font-family for body text.

Each row has an inline save button. Saving writes both the `site_settings.value` AND an `admin_activity_log` entry (`site_setting.updated`, payload `{key, old, new}`).

Validation is type-aware: `int` keys reject non-numeric input, `bool` keys accept only `true`/`false`, `json` keys validate as JSON.

### Environment-sourced secrets (read-only)

A separate section lists:

- `OPENAI_API_KEY`
- `ANTHROPIC_API_KEY`
- `ADZUNA_APP_ID`
- `ADZUNA_APP_KEY`
- `USAJOBS_AUTH_KEY`

For each: `(not set)` if empty, or a masked display showing first 4 + `…` + last 4 characters. These are not editable from the UI — they come from `.env`. To change them:

1. Edit `.env`.
2. Restart any long-running processes (Horizon, Reverb, queue worker).

### Theming workflow

Say you want to rebrand to "JobCopilot" with a different wordmark and blue accent:

1. Admin **Settings** page. Under Theming:
   - `brand_wordmark_url_override` → `https://your-cdn.com/jobcopilot-wordmark.svg`
   - `brand_color_oxblood_override` → `#2563eb` (blue)
2. Save.
3. Refresh a user page — the new wordmark + color show immediately.

No code changes, no deploy. The `BrandConfig` service reads the override first, falls back to `config/brand.php` defaults otherwise.

Some overrides (like font-family) require a Vite asset rebuild if you're injecting into compiled CSS. The color overrides use CSS variables at runtime, so they update without a rebuild.

---

## Audit log

`/admin/audit` — paginated view of `admin_activity_log`.

Columns:

- **Admin** — the admin user who performed the action (email)
- **Action** — dot-separated event name (`user.role_changed`, `user.impersonation_started`, `site_setting.updated`, `resume.parse_retried`, etc.)
- **Target** — `target_type` + `target_id` (e.g., `User#42`, `SiteSetting#rerank_enabled`)
- **IP** — source IP of the admin
- **Payload** — truncated JSON snippet of the event context (e.g., `{"from": "user", "to": "admin"}`)
- **When** — timestamp

Filters:

- **Action** — text search with `ILIKE '%text%'`
- **From date** / **To date** — inclusive range
- (Per-admin filtering is available server-side but not exposed in the UI — use the URL `?admin_id=N` if you want it)

### Event taxonomy

All admin actions that mutate state write a log entry. A non-exhaustive list:

| Action name | Target | Payload |
|---|---|---|
| `user.role_changed` | User | `{from, to}` |
| `user.deactivated` | User | `{}` |
| `user.reactivated` | User | `{}` |
| `user.impersonation_started` | User (the target) | `{admin_id}` |
| `user.impersonation_stopped` | User (the target) | `{}` |
| `site_setting.updated` | SiteSetting | `{key, old, new}` |
| `resume.parse_retried` | Resume | `{}` |
| `resume.embed_retried` | Resume | `{}` |
| `job.embed_retried` | CanonicalJob | `{}` |
| `match.rerank_retried` | JobMatch | `{}` |

### Append-only

The `AdminActivityLog` model enforces append-only at the Eloquent layer — calling `update()` on a persisted row throws `LogicException`. Corrections happen via compensating entries (e.g., if you accidentally flagged a user as admin, role-change them back to user; both events appear in the log).

---

## Error log

`/admin/errors` — four sections surfacing actionable failures.

### Failed parses

Resumes with `parse_status = parse_failed`. Usually means Claude returned unparseable JSON or the file was corrupted. Each row has a **Retry parse** button that:

- Sets `parse_status = pending`
- Dispatches a fresh `ParseResume` job
- Writes an audit entry `resume.parse_retried`

### Stuck resume embeddings

Resumes with `parse_status = parsed` (Claude succeeded) but `embedding IS NULL` for > 1 hour. Indicates the `EmbedResume` job got stuck — possibly cost-ceiling released and the day hasn't rolled over yet, or the queue worker was down.

**Retry embed** → dispatches `EmbedResume`, audit `resume.embed_retried`.

### Stuck job embeddings

Canonical jobs with null `embedding` for > 1 hour AND null `closed_at`. Same story as stuck resume embeddings, but for jobs. Usually means `EmbedJob` wasn't picked up or failed.

**Retry embed** → dispatches `EmbedJob`, audit `job.embed_retried`.

### Rerank unavailable

`job_matches` rows where `stage2_rationale = 'rerank unavailable'` (the sentinel value) AND `stage2_at` > 6 hours old. This is Claude's "I couldn't rerank this" state — a repeatable parse failure.

**Retry rerank** → dispatches `RerankShortlist` for the match's profile with shortlist_size=20. Audit `match.rerank_retried`.

### Empty error log

If all four sections are empty, nothing's actionable — the system is healthy.

---

## Horizon (queue dashboard)

`/horizon` — Laravel Horizon's built-in UI. Gated by the `viewHorizon` Gate, which checks `$user->isAdmin() && !$user->isDeactivated()`.

### What you see

Horizon's stock dashboard:

- **Dashboard** — active worker count, jobs per minute, current queue wait times
- **Queues** — per-queue stats (default queue is where everything goes in v1)
- **Jobs** — currently running, recent completions, pending
- **Monitored** — jobs you've tagged for detailed monitoring (not set up in v1)
- **Metrics** — throughput graphs, failed-jobs counts, average runtimes
- **Failed** — jobs that exceeded their retry budget. Click to see the exception, stack trace, payload. Can retry individually or in bulk.
- **Batches** — grouped jobs if you use Laravel's batch API (not used in v1)

### Watch for

- **Failed jobs** creeping up — usually indicates an external API outage (OpenAI/Anthropic down) or a deploy issue.
- **Queue wait time** spiking — you may need more queue workers. In v1, Horizon runs a single worker process; scale via the `horizon:work --name=...` pattern.
- **Throughput flatlining** — queue worker may be dead. Check the supervisor process.

### Retrying failed jobs

Click a failed job → **Retry**. It goes back on the queue. If it fails again the same way, inspect the stack trace — don't retry blindly.

**Bulk retry** the whole Failed list if you've fixed an upstream issue (e.g., restored an API key).

### Forgetting failed jobs

**Forget** removes a failed job from Horizon's view without retrying. Use sparingly — you lose the debugging context.

---

## CLI commands for admins

Artisan commands useful for dogfooding and emergency operations:

### `throughline:rerank-now {profile-id} [--size=20]`

Synchronously rerank a profile's top-K stale matches. Useful when you want to see the effect of a resume change without waiting for the scheduler, or when you're debugging a rerank issue.

```bash
php artisan throughline:rerank-now 42
php artisan throughline:rerank-now 42 --size=50
```

Produces output like:

```
Reranking top 20 stale matches for profile 42 (Staff frontend search)…
Done.
```

### `throughline:reminder-scan-now`

Runs `ApplicationReminderScan` synchronously. Writes any due reminder events + dispatches `SendReminderEmail` for opted-in users.

```bash
php artisan throughline:reminder-scan-now
```

Useful when testing the reminder flow end-to-end without waiting for the daily scheduler.

### `throughline:rebuild-vector-index {table} [--lists=N]`

Drops and recreates the ivfflat index on `resumes.embedding` or `canonical_jobs.embedding`. Defaults the `lists` parameter to `ceil(sqrt(rowCount))` per pgvector's recommendation.

```bash
php artisan throughline:rebuild-vector-index canonical_jobs
php artisan throughline:rebuild-vector-index resumes --lists=50
```

Run after bulk ingestion (the seeded index at migration time is sized for zero rows; after you have ~1k+ rows the index benefits from a rebuild).

### Horizon controls

```bash
php artisan horizon           # start horizon (prod)
php artisan horizon:pause     # pause all processing
php artisan horizon:continue  # resume
php artisan horizon:terminate # gracefully shutdown (waits for in-flight)
php artisan horizon:status    # current status
php artisan horizon:list      # list worker processes
```

### Reverb controls

```bash
php artisan reverb:start                      # start Reverb WebSocket server
php artisan reverb:start --host=0.0.0.0 --port=8080
php artisan reverb:restart                    # graceful restart
```

### Useful tinker snippets

```bash
php artisan tinker

# Give yourself admin
>>> User::where('email', 'me@example.com')->update(['role' => 'admin']);

# Find a stuck resume
>>> Resume::where('parse_status', 'parse_failed')->pluck('id', 'name');

# Force a profile rerank against current feed TTL
>>> App\Jobs\RerankShortlist::dispatchSync(SearchProfile::first()->id, 50, null);

# Reset a user's cost-ceiling window without waiting for midnight (delete today's events)
>>> DB::table('ai_usage_events')->where('user_id', 42)->where('created_at', '>=', now()->startOfDay())->delete();

# See recent audit entries
>>> AdminActivityLog::with('admin:id,email')->latest()->take(10)->get();
```

---

## What admins can't do in v1

Things that are intentionally out of scope for the v1 admin:

- **Bulk user operations** — no "promote 5 users at once" or bulk deactivate.
- **Cross-user message / notification sending** — no "send announcement to all users" UI.
- **Data export** (CSV of applications, users, jobs) — no built-in export UI. Use `psql` + `COPY TO` directly if you need a one-off.
- **Billing / subscription management** — out of v1 per the main product spec.
- **Direct SQL execution** from the admin UI — use `psql` / `tinker`.

All of these could be future-plan work if the need arises.

---

## Next steps

- [Operations doc](operations.md) — monitoring, backups, runbook.
- [Reference doc](reference.md) — exhaustive CLI + events + settings.
- [Architecture](architecture.md) — understand how the pieces fit before making changes.
