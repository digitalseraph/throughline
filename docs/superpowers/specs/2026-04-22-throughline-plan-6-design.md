# Throughline — Plan 6 design (polish, real-time, close)

**Status:** Design spec.

**Main product spec:** [`2026-04-21-throughline-design.md`](./2026-04-21-throughline-design.md).
**Plan 5 design:** [`2026-04-22-throughline-plan-5-design.md`](./2026-04-22-throughline-plan-5-design.md).

Plan 6 closes the 6-plan arc. It replaces the polling that Plans 2-5 wired in with Laravel Reverb real-time pushes, adds email notifications for overdue application reminders, lands the two deferred polish items from Plan 1's CLAUDE.md (brand-string leak guard, settings-driven theming), gives operators a maintenance command to retune pgvector's `ivfflat` index once data volume justifies, and ships a production-readiness checklist so the app is deploy-ready.

---

## 1. Scope & intent

### What Plan 6 delivers (user- and operator-visible)

- **Real-time updates** — `/resumes/{id}` no longer polls every 3 seconds waiting for parse + embed; the page subscribes to a private Reverb channel and renders the "Ready to match" state the moment the embedding lands. The nav reminder badge updates immediately when `ApplicationReminderScan` writes a new reminder event or when a user's `next_action_at` changes. The feed auto-reloads the top-K row when a fresh stage-2 rerank completes.
- **Email notifications** — a daily `ReminderDueMail` goes out when `ApplicationReminderScan` flags due applications. In local dev this lands in `MAIL_MAILER=log`; in prod it goes through the configured provider. Users can toggle email off via a profile setting (stored in `site_settings` per-user — see §2.5).
- **Brand-string leak guard** — a new feature test that grep-fails if the literal string `"Throughline"` appears in Vue templates or PHP source outside an allowlist (`BrandConfig`, `config/brand.php`, docs, tests).
- **Settings-driven theming** — `BrandConfig` honors `site_settings.brand_asset_overrides` per the main product spec §10. An admin can swap the wordmark URL, palette tokens, or typography stack without a code deploy.
- **pgvector retuning** — `php artisan throughline:rebuild-vector-index {table}` drops and recreates the `ivfflat` index on `resumes.embedding` or `canonical_jobs.embedding` with a `lists` parameter that scales with row count (`ceil(sqrt(N))` is the pgvector-recommended heuristic).
- **Production readiness checklist** — a new doc at `docs/production-readiness.md` enumerating deploy-time prerequisites (migrations, Horizon running as a service, Redis capacity, S3 bucket, Reverb process, env vars, monitoring hooks).

### What Plan 6 does NOT deliver

- **Scheduled reports / digest emails** beyond the single reminder email — out of v1.
- **Multi-tenant admin or billing surfaces** — explicitly out of v1 per the main spec.
- **Auto-apply / form fill** — out of v1 per the main spec.
- **Resume generation / per-job tailoring** — out of v1 per the main spec.
- **Plan 7 work** — there is no Plan 7. Post-Plan-6 the product ships to beta.

### Sequencing principle

Deliver the deferred polish items in roughly the order Plans 2-5 assumed they'd land: brand-string guard and settings theming are small and clear the decks; the Reverb work ships in a stable order (install → broadcast events → frontend subscriptions → replace polling); email notifications sit alongside the reminder scan; operational tooling (pgvector retune + readiness doc) at the end.

1. Brand-string leak guard + any refactors it surfaces
2. Settings-driven theming (BrandConfig extensions + admin UI surface)
3. Install Laravel Reverb + broadcasting config
4. Resume parse/embed completion events + `/resumes/{id}` subscribes → drops polling
5. Reminder badge real-time + nav subscribes
6. Feed stage-2 arrival events + feed auto-refresh (optional — punt if complexity spikes)
7. Email notifications for `ReminderDue`
8. pgvector ivfflat rebuild artisan command
9. Production readiness doc
10. Final verification + docs rewrite + session close

---

## 2. Architecture additions

### 2.1 Laravel Reverb

- Install `laravel/reverb` via composer. Configure Pusher-compatible WebSocket broadcasting in `config/broadcasting.php` (driver: `reverb`).
- Run `php artisan reverb:start` as a separate dev process — wire it into `composer run dev` so the full stack spins up together.
- `compose.yaml` gains a `reverb` service so Docker Compose starts it automatically. Prod runs it as a supervisord/systemd service (documented in the readiness doc).
- Broadcasting uses private channels (`Broadcast::channel('users.{userId}', ...)` authorizes by `$user->id === $userId`). Each user subscribes to `users.{id}` for anything that concerns them.

### 2.2 Broadcast events

New broadcast events under `App\Events`:

- `ResumeReady(Resume $resume)` — fired by `EmbedResume` after the vector write. Broadcasts on `users.{userId}` with payload `{resume_id, has_embedding: true, parse_status}`.
- `ResumeParseFailed(Resume $resume)` — fired by `ParseResume` when it transitions to `parse_failed`. Broadcasts on `users.{userId}`.
- `ReminderBadgeUpdated(User $user, int $count)` — fired whenever the badge count changes: (a) `ApplicationReminderScan` writes new events, (b) a user updates an application's `next_action_at`, (c) a user marks a terminal status. Broadcasts on `users.{userId}` with the fresh count.
- `FeedMatchReranked(SearchProfile $profile, int $matchId)` — fired by `RerankShortlist` after the last batch lands for a profile. Broadcasts on `users.{userId}`.

Events implement `ShouldBroadcast`. Payloads include only primitive data — no Eloquent serialization edge cases.

### 2.3 Frontend subscriptions

- `resources/js/echo.ts` — instantiates Laravel Echo pointed at the Reverb server. Wires `PUSHER_APP_KEY` (Reverb uses the same env var) from Vite.
- `resources/js/composables/useUserChannel.ts` — composable that subscribes to `users.{authId}` on mount, exposes an `on(event, handler)` API, unsubscribes on unmount.
- `resources/js/pages/resumes/Show.vue` — drops the `setInterval` polling. Subscribes to `ResumeReady` and `ResumeParseFailed`. Polling remains as a 30-second fallback in case the WebSocket drops — but the normal case is sub-second updates.
- Nav badge — `AppSidebar.vue` (or the equivalent nav component) subscribes to `ReminderBadgeUpdated` and updates the count in place.
- Feed — `feed/Show.vue` subscribes to `FeedMatchReranked`; on event, it calls `router.reload({only: ['matches']})` to fetch fresh stage-2 data without a full page rerender.

### 2.4 Brand-string leak guard

- New feature test `BrandStringLeakTest` extends the existing `NoHardcodedHostsTest` pattern: recursively scan `resources/js/**/*.vue`, `resources/views/**/*.blade.php`, and `app/**/*.php` for the literal string `"Throughline"` (case-insensitive, word-boundaried). Allowlist paths: `app/Services/Brand/**`, `config/brand.php`, `tests/**`, `docs/**`, comments.
- Expected: the test passes today because Plan 1's `BrandConfig` consolidated brand string access. If the test surfaces leaks from Plan 2-5 work, refactor them to read from `BrandConfig`/`site_settings` instead.

### 2.5 Settings-driven theming

- `SiteSettings` gains these keys (via seeder, `firstOrCreate`):
  - `brand_wordmark_url_override` (string, default null) — URL to a custom SVG wordmark.
  - `brand_favicon_url_override` (string, default null).
  - `brand_color_primary_override` (string, default null) — hex.
  - `brand_color_oxblood_override` (string, default null).
  - `brand_font_sans_override` (string, default null) — CSS font-family.
- `BrandConfig` methods read override first, fall back to `config/brand.php` default.
- The admin Settings page from Plan 5 renders these in a distinct "Theming" group so operators find them easily.
- User's `notification_email_enabled` lives in the `users` table (new column, default true) — each user opts out individually, not via site-wide settings.

### 2.6 Email notifications

- `App\Mail\ReminderDueMail` — Mailable rendered from `resources/views/mail/reminder-due.blade.php`. Lists the due applications with title / company / status / `next_action_at`. Links back to `/applications/{id}`.
- `ApplicationReminderScan` (the Plan 4 Task 8 scheduled job) is extended: after writing reminder events for a user, if the user has `notification_email_enabled = true`, the scan dispatches a `SendReminderEmail($user, $applicationIds)` queued job. The queued job checks the count hasn't changed (handles the race where a user acknowledges a reminder between scan and send) and then sends the mail.
- Testing: `Mail::fake()` + assert sent to the right user.

### 2.7 pgvector rebuild command

- `App\Console\Commands\RebuildVectorIndexCommand` with signature `throughline:rebuild-vector-index {table : resumes|canonical_jobs} {--lists= : override the auto-computed lists count}`.
- Drops the existing ivfflat index (by a known name — `resumes_embedding_ivfflat` and `canonical_jobs_embedding_ivfflat`). Counts rows, computes `ceil(sqrt(N))` when `--lists` isn't passed, and issues `CREATE INDEX ... USING ivfflat(embedding vector_cosine_ops) WITH (lists = {N})`.
- Runs inside a DB transaction with a statement timeout override; logs the before/after time.

### 2.8 Production readiness doc

`docs/production-readiness.md` — a checklist covering:
- Required env vars (full list)
- External services (Postgres 16+pgvector, Redis, Reverb process, S3-compatible storage for file uploads, OpenAI/Anthropic API keys, Adzuna/USAJobs keys for those drivers)
- Background processes (Horizon worker, scheduler via cron, Reverb server)
- Migrations to run (`php artisan migrate --force`)
- Seeders to run on first deploy (`SiteSettingsSeeder`, `JobSourcesSeeder`)
- Monitoring hooks (Horizon metrics, log aggregation, failed-queue-jobs alert)
- Security — APP_KEY rotation guidance, session driver recommendation, CSP note
- Backup strategy — Postgres dumps, file storage replication
- Rollback procedure — migration down-steps, queue drain

---

## 3. Data model additions

### 3.1 Migrations

One new migration: add `notification_email_enabled` column to `users`. Defaults to `true`.

```
ALTER TABLE users ADD COLUMN notification_email_enabled boolean NOT NULL DEFAULT true;
```

### 3.2 Seed expansions

`SiteSettingsSeeder` appends 5 new keys:

- `brand_wordmark_url_override` (string, default null)
- `brand_favicon_url_override` (string, default null)
- `brand_color_primary_override` (string, default null)
- `brand_color_oxblood_override` (string, default null)
- `brand_font_sans_override` (string, default null)

No changes to `JobSourcesSeeder` or the 8-driver set.

---

## 4. Data flow

### 4.1 Flow H — Resume upload real-time completion

1. User uploads resume (Plan 2 flow).
2. `ParseResume` runs → writes `parsed_text` + `structured_json` + `parse_status`. On `parse_failed`, fires `ResumeParseFailed` event.
3. On success → dispatches `EmbedResume`.
4. `EmbedResume` writes the vector → fires `ResumeReady` event.
5. `/resumes/{id}` is subscribed to `users.{userId}`; receives the event → updates local state to `{parse_status: 'parsed', has_embedding: true}`. No router reload needed — the Vue state machine re-renders from the new props.

### 4.2 Flow I — Reminder badge real-time

1. `ApplicationReminderScan` writes reminder events.
2. For each user touched, fires `ReminderBadgeUpdated(user, newCount)`.
3. Nav component receives the event → updates the `$page.props.reminderBadgeCount` shared prop value in local state, re-renders the badge immediately.
4. When a user edits `next_action_at` on an application → the controller's update path also fires `ReminderBadgeUpdated` after the save → nav badge updates across all open tabs.

### 4.3 Flow J — Feed stage-2 arrival

1. Feed controller's sync-rerank dispatch (`dispatchSync`) fires `FeedMatchReranked` after writing the stage-2 columns.
2. User's open feed page (including other tabs) receives the event → triggers `router.reload({only: ['matches']})` → fresh stage-2 data loads.
3. Nightly `NightlyRerankRefresh` also fires `FeedMatchReranked` when it completes a profile's rerank.

### 4.4 Flow K — Email reminder

1. Daily `ApplicationReminderScan` groups due applications by user.
2. For each user with `notification_email_enabled = true` AND at least one new reminder event written today → dispatches `SendReminderEmail($user, $applicationIds)`.
3. `SendReminderEmail::handle()` re-checks the user's email preference + application count (race-safe) → sends `ReminderDueMail`.

### 4.5 Failure modes

- Reverb server down → frontend falls back to 30-second polling on `/resumes/{id}` and accepts staleness on the nav badge (user will see the fresh count on next navigation). No user-visible error; just reduced responsiveness.
- Mail driver misconfigured / throws → `SendReminderEmail` job is released for retry per Horizon defaults. After 3 retries, it's failed-queue'd and visible in the admin Horizon view.
- Broadcast event serialization error → the dispatching job logs a warning and continues. The main operation (resume embed, reminder write) still succeeds; only the push notification is lost.

---

## 5. Key interfaces

### 5.1 Broadcast events

```php
class ResumeReady implements ShouldBroadcast {
    public function __construct(public Resume $resume) {}
    public function broadcastOn(): PrivateChannel { return new PrivateChannel("users.{$this->resume->user_id}"); }
    public function broadcastWith(): array {
        return ['resume_id' => $this->resume->id, 'parse_status' => 'parsed', 'has_embedding' => true];
    }
}
```

Same shape for `ResumeParseFailed`, `ReminderBadgeUpdated`, `FeedMatchReranked`.

### 5.2 `BrandConfig` extensions

```php
class BrandConfig {
    public function wordmarkUrl(): string;     // checks site_settings.brand_wordmark_url_override, falls back to config/brand.php
    public function faviconUrl(): string;
    public function palette(): array;          // returns map with override-aware colors
    public function fontSans(): string;
}
```

Existing callers don't need to change; the service is the single indirection point.

### 5.3 Artisan commands

- `throughline:rebuild-vector-index {table} [--lists=N]` — covered in §2.7.
- Existing commands (`throughline:rerank-now`, `throughline:reminder-scan-now`) remain unchanged.

---

## 6. Error handling & testing strategy

### 6.1 Error-handling deltas

- Every broadcast dispatch site wraps the event firing in a try/catch that logs a warning but never aborts the primary DB write. A failed push must not cause the resume to not-be-parsed.
- `SendReminderEmail::handle()` idempotent — checking the `notification_email_enabled` flag and the same-day event count before sending prevents duplicate emails on retry.

### 6.2 New tests

- `BrandStringLeakTest` — scans the tree for unapproved `"Throughline"` occurrences.
- `BrandConfigOverrideTest` — site_settings overrides take precedence over config/brand.php.
- `ReminderDueMailTest` — renders correctly with seeded applications.
- `SendReminderEmailTest` — dispatched only when the user has the flag enabled; respects the race-check.
- `ResumeReadyBroadcastTest` — `EmbedResume::handle` fires the event (use `Event::fake`).
- `ReminderBadgeUpdatedBroadcastTest` — same.
- `RebuildVectorIndexCommandTest` — command drops + recreates the index with the expected `lists` parameter.

### 6.3 E2E

The Playwright spec gains one assertion: after uploading a resume, the "Ready to match" text shows up without the test having to wait on a polling tick (the test succeeds faster). This indirectly validates the Reverb path.

---

## 7. Preconditions, setup, and docs updates

### 7.1 New composer dependencies

- `laravel/reverb` (published via `php artisan reverb:install`).

### 7.2 New npm dependencies

- `laravel-echo` + `pusher-js` (Reverb uses the Pusher protocol). Both are installed via the Reverb scaffold but call out explicitly.

### 7.3 Env var additions

- `BROADCAST_CONNECTION=reverb` (override from the Plan 1 default of `log`).
- `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` — published by Reverb.
- `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` — frontend sees these.

### 7.4 compose.yaml

New `reverb` service running `php artisan reverb:start` with the Herd PHP binary on the host (mapped via a bind-mount) or using the `pgvector/pgvector:pg16`-alongside container image. Simpler: just document that Reverb runs alongside `composer run dev` on the host, not in Docker, since the webserver already runs there.

### 7.5 CLAUDE.md + README updates

New bullets covering:
- Reverb as the real-time driver; dev loop requires `php artisan reverb:start` alongside `composer run dev`.
- Broadcast event contract: all events go on `users.{id}` private channels; payloads are primitives only.
- Settings-driven theming — `BrandConfig` reads `site_settings` overrides with fallback to `config/brand.php`.
- Email notifications — `MAIL_MAILER=log` in dev; production sets the provider. Users opt out via their profile (`notification_email_enabled` column).

### 7.6 Production readiness doc

Sits at `docs/production-readiness.md`. Not linked from CLAUDE.md (which is dev-focused) but is linked from README.

---

## 8. Definition of done for Plan 6

- `composer run dev` + `docker compose up -d` + `php artisan reverb:start` spins up a fully working app.
- Uploading a resume surfaces "Ready to match" within 1-2 seconds of the embed completing (no 3-second polling wait).
- Adding a `next_action_at` due-today value on an application updates the nav badge count live.
- Running `php artisan throughline:reminder-scan-now` with `MAIL_MAILER=log` produces a log entry for the reminder email.
- `php artisan throughline:rebuild-vector-index canonical_jobs` drops and recreates the ivfflat index.
- `BrandStringLeakTest` is green.
- Admin Settings page shows the five new theming keys; editing one updates the rendered wordmark/favicon/palette without a code change.
- Full PHP suite passes (~230 tests expected).
- Playwright E2E passes.
- `docs/production-readiness.md` is committed and links from `README.md`.
- CLAUDE.md + README reflect Plan 6.
