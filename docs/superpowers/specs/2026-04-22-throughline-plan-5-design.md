# Throughline — Plan 5 design (admin dashboard)

**Status:** Design spec.

**Main product spec:** [`2026-04-21-throughline-design.md`](./2026-04-21-throughline-design.md). **Plan 4 design:** [`2026-04-22-throughline-plan-4-design.md`](./2026-04-22-throughline-plan-4-design.md).

This document scopes **Plan 5 of 6** — the admin dashboard that turns the operational data Plans 2-4 already produce (`ai_usage_events`, `job_sources`, `canonical_jobs`, `applications`, and friends) into a single place for the operator to see what's happening, spend what's being spent, and intervene when something's wrong.

---

## 1. Scope & intent

### What Plan 5 delivers (operator-visible)

An admin opens `/admin` and sees:

- **Overview** — cards showing total users, 7-day active users, total canonical jobs, jobs ingested today, total `job_matches`, applications grouped by status, AI spend today + this month, source-health summary (green = recently fetched, amber = stale, red = auto-paused or error).
- **Users** — paginated table with search (email or name), linked auth providers, role, `last_login_at`, application count, resume count. Detail view lets the admin edit role, impersonate (with persistent banner + audit log entries on start/stop), and soft-deactivate.
- **Jobs browser** — read-only paginated table over `canonical_jobs` with filters (source, company, posted date, embedding-present, closed status). Click-through opens a detail panel showing the raw payload + all `job_matches` that reference it + any `applications` downstream.
- **Analytics** — time-series charts: daily ingestion by source, daily matches generated, rerank calls, **AI cost trend (joined `ai_usage_events.context_id` → `search_profile_id` → `user_id` per Plan 3 Task 2's fix)**, application funnel (saved → applied → interview → offer). Simple line/bar charts via Chart.js (new npm dep); no real-time, plain daily aggregates.
- **Settings** — key/value editor over `site_settings`. Env-sourced secrets (OpenAI/Anthropic/Adzuna/USAJobs keys) render read-only + masked. Editable rows get an inline textarea or number input; save writes back via form POST.
- **Audit log** — paginated view of `admin_activity_log`. Every row: admin user, action, target type + id, IP, payload snippet, timestamp. Filter by admin user + action type + date range.
- **Error log** — failed resume parses (`parse_status = parse_failed`), failed embeddings (resumes/jobs with non-null `source_file_path` / embeddable content but null embedding for > N hours), and rerank failures (`job_matches.stage2_rationale = 'rerank unavailable'`). Each row has a retry button that re-dispatches the appropriate job.
- **Horizon** — mounted at `/admin/horizon` behind the existing `EnsureAdmin` middleware. Uses Laravel Horizon's default Vue dashboard; no customization beyond the access gate.

The existing `/admin/job-sources` page from Plan 2 Task 14 stays intact and becomes part of the nav.

### What Plan 5 ALSO delivers (plumbing operators don't see)

- **`admin_activity_log` table + `AdminLogger` service** — every mutating admin action goes through `AdminLogger::record($admin, $action, $target, $payload)`. Enforced by a controller base class or middleware; impersonation start/stop are special-cased.
- **`Admin\*\Controller` namespace structure** — all admin pages live under `App\Http\Controllers\Admin\*` with a shared base that applies `EnsureAdmin` at controller construction and wraps mutating actions in an AdminLogger call.
- **Analytics aggregation layer** — `App\Services\Admin\Analytics` with typed methods that produce time-series arrays ready for Chart.js. No caching in v1; if the queries get slow, Plan 6 can add a daily-aggregates table.

### What Plan 5 does NOT deliver

- **Row-level audit of user actions** — Plan 5 logs admin actions only. User self-service actions (save, status transition, contact edit) aren't logged centrally in v1; their per-application `application_events` timeline (Plan 4) is the surface.
- **Impersonation-within-impersonation or cross-admin trails** — an admin can impersonate any non-admin user, but can't chain impersonations. Nested impersonation is explicitly blocked with a 403.
- **Bulk admin mutations** — no "promote 5 users to admin at once" UI. Individual mutations only in v1.
- **Scheduled report exports / email digests for admins** — Plan 6.
- **Usage / API rate caps per user or org** — already enforced by the `AiClient` cost ceiling in Plan 2/3; admin just observes.
- **Billing / subscription views** — out of v1 per the main product spec.

### Sequencing principle

Non-destructive reads first, mutating writes last. Concretely:
1. `admin_activity_log` migration + model + `AdminLogger` service.
2. Admin controller base + nav redesign.
3. Overview page (all reads, no writes).
4. Users table + detail (reads first, then role-edit + impersonation + deactivate writes).
5. Jobs browser (read-only).
6. Analytics page.
7. Settings editor (mutating, routes write audit log rows).
8. Audit log viewer.
9. Error log viewer + retry actions.
10. Horizon mount.
11. Docs + final verification.

---

## 2. Architecture additions

### 2.1 `admin_activity_log` table + `AdminLogger`

- **Table**: `id`, `admin_user_id` (FK, cascade), `action` (e.g. `user.role_changed`, `user.impersonation_started`, `site_setting.updated`, `rerank.retried`), `target_type` (nullable string, e.g. `User`, `SiteSetting`, `JobMatch`), `target_id` (nullable), `payload` (jsonb — before/after for edits, metadata for non-edit events), `ip` (nullable string), `created_at` (no updated_at — append-only).
- **Model**: `AdminActivityLog`. Append-only via `booted()` updating-listener (same pattern as `ApplicationEvent` from Plan 4 Task 2).
- **`AdminLogger` service** — single writer. Methods: `record(User $admin, string $action, mixed $target, array $payload = []): AdminActivityLog`. Resolves `target_type` from the target's class basename; resolves `target_id` from `$target->id`. Accepts null target for global events (like impersonation start).

### 2.2 `Admin\BaseController`

All admin controllers extend this base. The base:
- Applies `EnsureAdmin` middleware in its constructor via `$this->middleware(EnsureAdmin::class)`.
- Exposes a helper `$this->audit($action, $target, $payload)` that wraps `AdminLogger::record` with the current admin user + request IP.

### 2.3 Impersonation

- `ImpersonationController@start($user)` — verifies the admin isn't already impersonating (no nesting), verifies target isn't an admin (admins can't be impersonated, in v1), stashes `original_user_id` in the session, logs in as target. Writes audit log entry.
- `ImpersonationController@stop()` — reads `original_user_id` from session, logs back in as admin, clears session key. Writes audit log entry.
- Frontend: a persistent banner (`<ImpersonationBanner>`) renders when `session.original_user_id` is present (surfaced via Inertia shared prop `isImpersonating` + target name). The banner has a single button: "Stop impersonating".
- Route guard: `POST /admin/impersonate/{user}/start` and `POST /admin/impersonate/stop`. The "start" route is gated by `EnsureAdmin`; the "stop" route is gated by `auth` only (you're no longer logged in as the admin, you're the impersonated user with `original_user_id` in session).

### 2.4 Analytics service

`App\Services\Admin\Analytics` — produces time-series data ready for Chart.js consumption.

- `dailyIngestion(int $days = 30): array` — last N days, grouped by date + source.
- `dailyMatches(int $days = 30): array` — count of `job_matches` created per day.
- `dailyRerankCalls(int $days = 30): array` — count of `ai_usage_events` rows where `operation='rerank'` per day.
- `dailyAiCost(int $days = 30, ?int $userId = null): array` — sum of `estimated_cost_usd` per day. When `$userId` is null (default), returns system-wide totals; when set, filters by `user_id = $userId` AND by the context_id→profile→user join path for rerank attribution (Plan 3 Task 2's fix).
- `applicationFunnel(): array` — count per status across all users.
- `sourceHealth(): array` — per-source: `last_fetched_at`, `consecutive_failures`, `is_enabled`. Surfaces auto-paused sources prominently.

Returns plain arrays; no chart library coupling. The Vue pages consume via Chart.js.

### 2.5 Admin frontend layout

- `resources/js/layouts/AdminLayout.vue` — admin-specific shell with its own sidebar (Overview / Users / Jobs / Matches / Analytics / Settings / Audit / Errors / Sources / Horizon). Reuses the Plan 1 brand tokens; visually distinct from the user-facing nav (narrower sidebar, tighter spacing). `AppLayout` stays as the user-facing surface.
- `resources/js/components/admin/ImpersonationBanner.vue` — persistent red bar across the top when impersonating.

### 2.6 Error-log rules

- `App\Services\Admin\ErrorLog` — produces a paginated list of actionable failures:
  - Resumes with `parse_status = parse_failed` (not yet retried in the last hour)
  - Resumes with `parse_status = parsed` but null embedding for > 1 hour (embed stuck)
  - Jobs with null embedding for > 1 hour
  - `job_matches` with `stage2_rationale = 'rerank unavailable'` older than 6 hours
- Each row has a retry action wired to the appropriate job dispatch (`ParseResume`, `EmbedResume`, `EmbedJob`, `RerankShortlist`). Retry dispatches write audit log entries tagged `*.retried`.

### 2.7 Horizon mount

Install Laravel Horizon (already in composer from Plan 1) and ensure it's properly configured via `config/horizon.php`. Mount the UI at `/admin/horizon` gated by `EnsureAdmin`. Horizon ships its own Vue dashboard; no custom skin.

---

## 3. Data model additions

### 3.1 Migrations

One new table: `admin_activity_log`.

```
id bigint pk
admin_user_id bigint fk -> users cascade
action string(64)
target_type string(64) nullable
target_id bigint nullable
payload jsonb default '{}'
ip string(64) nullable
created_at timestamp default now
```

Indexes: `(admin_user_id, created_at)` for per-admin history, `(action, created_at)` for action filters, `(target_type, target_id)` for target-centric queries.

No changes to existing tables. Plan 5 reads from Plans 1-4 tables exclusively.

### 3.2 Seed expansions

No new site_settings keys. Plan 5 adds no defaults.

No new `job_sources` or drivers.

### 3.3 Horizon tables

Laravel Horizon's own migration set (already shipped via `horizon:install` during Plan 1). Re-run to ensure the tables exist on fresh clones.

---

## 4. Data flow

### 4.1 Admin navigation

1. Admin user logs in normally. Sidebar renders the Applications / Feed / Resumes links. Because `user.role === 'admin'`, an extra "Admin" link appears in a new top-level nav group.
2. Click → navigate to `/admin`. Rendered inside `AdminLayout`. Overview cards populate from a single Inertia page's props (the controller fetches everything in one request via the Analytics service).

### 4.2 Impersonation

1. Admin opens `/admin/users`, clicks a user's detail row, clicks "Impersonate".
2. POST `/admin/impersonate/{user}/start` → ImpersonationController checks invariants → stashes `original_user_id` in session → `Auth::login($target)` → audit log entry → redirects to `/dashboard`.
3. `HandleInertiaRequests` shares `isImpersonating` + `impersonationOriginalName` + `impersonationTargetName` as Inertia props based on session state.
4. `ImpersonationBanner.vue` renders across the top of every page while impersonating.
5. Admin clicks "Stop impersonating" → POST `/admin/impersonate/stop` → reads session key → `Auth::login($originalAdmin)` → clears session key → audit log entry → redirects to `/admin/users`.

### 4.3 Settings edit

1. Admin opens `/admin/settings`. Settings render grouped by category (AI / Matching / Reminders / Brand / Other). Env-sourced secrets render as disabled inputs with value masked.
2. Admin edits a value → inline save button → POST `/admin/settings/{key}` → controller loads old value, updates, writes audit log entry with `{old, new}` payload → invalidates SiteSettings cache → returns updated props via Inertia.

### 4.4 Error-log retry

1. Admin clicks "Retry" on a parse-failed resume row.
2. POST `/admin/errors/resumes/{resume}/retry` → ErrorController dispatches `ParseResume::dispatch($resume->id)` → writes audit log entry `resume.parse_retried`.
3. Row moves out of the error log on next pagination refresh once the job completes.

### 4.5 Failure modes

- Impersonation target is another admin → 403, audit log entry NOT written (blocked before the login switch).
- Nested impersonation attempt → 403.
- Settings write on a non-existent key → 404.
- Settings write on an env-sourced secret via the form → 422 "Secrets are read-only; set via .env".
- Retry on an error-log row whose underlying record was deleted → 404 with friendly message.

---

## 5. Key interfaces

### 5.1 `AdminLogger::record()`

```php
class AdminLogger {
    public function record(User $admin, string $action, mixed $target = null, array $payload = []): AdminActivityLog;
}
```

Target resolution: null → no `target_type`/`target_id`; Eloquent model → basename + `->id`; arbitrary string → treated as a `target_type` with null id.

### 5.2 `Analytics` service

As defined in §2.4. Returns plain PHP arrays of the shape:

```php
['labels' => ['2026-03-23', '2026-03-24', ...], 'datasets' => [['label' => 'Greenhouse', 'data' => [120, 145, ...]], ...]]
```

Chart.js ingests this directly.

### 5.3 Admin routes

```
GET    /admin                                          Overview
GET    /admin/users                                    Users list
GET    /admin/users/{user}                             User detail
PATCH  /admin/users/{user}                             Update role / deactivate
POST   /admin/impersonate/{user}/start                 Start impersonation
POST   /admin/impersonate/stop                         Stop impersonation
GET    /admin/jobs                                     Jobs browser (read-only)
GET    /admin/jobs/{job}                               Job detail
GET    /admin/analytics                                Analytics page
GET    /admin/settings                                 Settings editor
PATCH  /admin/settings/{key}                           Update a setting
GET    /admin/audit                                    Audit log viewer
GET    /admin/errors                                   Error log
POST   /admin/errors/resumes/{resume}/retry            Retry parse/embed
POST   /admin/errors/jobs/{job}/retry-embed            Retry job embed
POST   /admin/errors/matches/{match}/retry-rerank      Retry rerank
GET    /admin/horizon/...                              Horizon (native UI)
```

All prefixed under `/admin`, all gated by `EnsureAdmin`. Existing Plan 2 `/admin/job-sources` routes stay in place.

---

## 6. Error handling & testing strategy

### 6.1 Error-handling deltas

- `AdminLogger::record` never throws — a log write failure is warned to the application log but doesn't break the admin action. Rationale: losing an audit row is bad, but breaking the admin's workflow because the log table is temporarily unreachable is worse.
- Impersonation invariants are checked BEFORE any session mutation or audit write. A failed start leaves zero side effects.
- Settings edit validates the value against the key's declared `type` (string/int/bool/json) from `site_settings.type`. Bad type → 422.

### 6.2 New feature tests

- `AdminActivityLogAppendOnlyTest` — updating throws LogicException.
- `AdminLoggerTest` — record() with various target shapes produces correct rows; null target; Eloquent model; string-only target.
- `AdminOverviewTest` — admin sees cards with correct aggregate numbers; non-admin forbidden.
- `AdminUserManagementTest` — list/search/detail; role update writes audit log; deactivate; non-admin forbidden.
- `ImpersonationTest` — start/stop flow, nesting forbidden, admin-target forbidden, audit log entries.
- `AdminJobsBrowserTest` — filters work, detail shows matches + downstream applications.
- `AnalyticsServiceTest` — deterministic fixture data produces the expected time-series output per method.
- `AdminSettingsTest` — read + write; env-secrets are read-only; invalid type rejected; audit log written.
- `AdminAuditLogViewerTest` — filter by admin/action/date works; pagination.
- `AdminErrorLogTest` — each category populates correctly; retry actions dispatch the right jobs + audit entries.

### 6.3 Horizon

No new Horizon tests — the package is well-tested upstream. One feature test asserts the Horizon route is gated by `EnsureAdmin`.

### 6.4 E2E

Extend the Playwright spec with a single additional flow: log in as admin, open `/admin`, verify the Overview page renders, navigate to `/admin/users`, open own detail (no impersonation of self). Kept lightweight; the admin surface's test confidence comes from the PHP feature tests.

---

## 7. Preconditions, setup, and docs updates

### 7.1 New dependencies

- `chart.js@^4.4.0` + `vue-chartjs@^5.3.0` (npm). Analytics page only.
- No new composer packages (Horizon already installed).

### 7.2 Horizon config

Publish `config/horizon.php` via `php artisan horizon:install` if not already published from Plan 1. Set `HORIZON_DOMAIN` env (optional; default null = same-domain mount). Ensure the Horizon service provider is registered.

### 7.3 CLAUDE.md updates

Three new architecture bullets:
- Admin actions write to `admin_activity_log` via `AdminLogger`. Every mutating admin controller action must call `$this->audit(...)` (helper on the admin base controller).
- Impersonation is a one-level operation (no nesting). Original admin id lives in session under `original_user_id`; the `<ImpersonationBanner>` shows whenever that key is set.
- `Analytics` service produces Chart.js-ready arrays; no database-level aggregates table in v1.

New `/admin` sitemap added to the README for quick reference.

### 7.4 README.md updates

"What's here now" gains the admin dashboard surfaces.

---

## 8. Deferred to Plan 6

- **Reverb real-time push** — admin overview cards could refresh live; punted to Plan 6 where the whole real-time path lands.
- **Brand-string leak test** (identified in the original CLAUDE.md as a future-plan item).
- **pgvector ivfflat retuning** once data volume justifies — no admin surface needed, just a maintenance command.
- **Daily aggregates table** for Analytics if queries become slow. v1 hits raw tables directly.
- **Settings-driven theming overrides** in the admin Settings page.
- **Email digests for overdue reminders** and admin alerts.
- **Full data export** (CSV of applications, users, etc.) — not a v1 requirement per the main spec.

---

## 9. Definition of done for Plan 5

- A first-user-auto-admin who logs in sees an "Admin" link in the user nav. Clicking it lands on `/admin` inside `AdminLayout`.
- Overview renders populated cards (even with zero data — "No users yet" style empty states where appropriate).
- Users table filters by email/name, opens a detail with role + impersonate + deactivate actions. Impersonation shows the banner; clicking stop restores the admin session.
- Jobs browser paginates, filters work, job detail shows the raw payload and downstream matches + applications.
- Analytics renders 5 charts with real data from the testing DB (Cost chart shows USD totals per day; application funnel shows counts per status).
- Settings edits write to `site_settings` AND `admin_activity_log` in one transaction. Env-secrets render masked + read-only.
- Audit log viewer paginates correctly and filters on admin/action/date.
- Error log surfaces parse-failed resumes, stuck embeddings, and rerank-unavailable matches; retry actions dispatch the correct job.
- Horizon mount at `/admin/horizon` renders Laravel's native dashboard; non-admin gets 403.
- All new feature tests green. PHP suite should land around 210-225 tests.
- CLAUDE.md + README reflect Plan 5.
