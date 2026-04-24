# Reference

Exhaustive lookups for developers and operators: CLI commands, broadcast events, `site_settings` keys, job-source drivers, environment variables.

## Table of contents

- [Artisan commands](#artisan-commands)
- [Broadcast events](#broadcast-events)
- [Queued jobs](#queued-jobs)
- [Scheduled jobs](#scheduled-jobs)
- [`site_settings` keys](#site_settings-keys)
- [Job source drivers](#job-source-drivers)
- [Environment variables](#environment-variables)
- [Admin routes](#admin-routes)
- [User-facing routes](#user-facing-routes)

---

## Artisan commands

### Throughline-specific

| Command | Purpose |
|---|---|
| `throughline:rerank-now {profile} [--size=N]` | Synchronously rerank top-K stale matches for a profile. |
| `throughline:reminder-scan-now` | Run `ApplicationReminderScan` synchronously — useful for testing reminder flow. |
| `throughline:rebuild-vector-index {table} [--lists=N]` | Drop + recreate the ivfflat index on `resumes` or `canonical_jobs`. Defaults `lists` to `ceil(sqrt(rowCount))`. |

### Laravel defaults

| Command | Purpose |
|---|---|
| `migrate` | Run pending migrations. |
| `migrate --seed` | Migrate + run `DatabaseSeeder`. |
| `migrate:fresh --seed` | Drop everything + re-migrate + re-seed. Dev-only. |
| `db:seed --class=SiteSettingsSeeder` | Seed just site settings. |
| `db:seed --class=JobSourcesSeeder` | Seed just the 11 starter job sources. |
| `queue:listen` | Dev-only queue worker (reloads on code change). |
| `queue:work` | Production queue worker (use Horizon instead). |
| `horizon` | Start Horizon queue supervisor. |
| `horizon:terminate` | Graceful shutdown — waits for in-flight jobs. |
| `horizon:pause` / `horizon:continue` | Pause/resume queue processing. |
| `reverb:start [--host=0.0.0.0] [--port=8080]` | Start Reverb WebSocket server. |
| `reverb:install` | Publish Reverb config + wire env vars (one-time). |
| `tinker` | REPL in app context. |
| `route:list` | List all registered routes. |
| `config:cache` | Cache config for production performance. |
| `config:clear` | Clear cached config (after `.env` changes). |
| `cache:clear` | Clear application cache (site_settings values, reminder badge counts). |
| `queue:failed` | List failed jobs. |
| `queue:retry {id|all}` | Retry failed job(s). |
| `queue:flush` | Delete all failed jobs. |

---

## Broadcast events

All events broadcast on private `users.{userId}` channels. Payloads are primitives only — no Eloquent serialization.

| Event class | Fired by | Payload | Subscribed to by |
|---|---|---|---|
| `App\Events\ResumeReady` | `EmbedResume::handle` after vector write | `{resume_id: int, parse_status: 'parsed', has_embedding: true}` | `/resumes/{id}` page |
| `App\Events\ResumeParseFailed` | `ParseResume::handle` on transition to `parse_failed` | `{resume_id: int, parse_status: 'parse_failed', has_embedding: false}` | `/resumes/{id}` page |
| `App\Events\ReminderBadgeUpdated` | `ApplicationReminderScan`, `ApplicationController@update`, `ApplicationController@destroy` | `{count: int}` | Nav component (`NavMain.vue`) |
| `App\Events\FeedMatchReranked` | `RerankShortlist::handle` after successful apply | `{search_profile_id: int, updated_at: string}` | `/feed/{profile}` page |

### Broadcast name (`broadcastAs`)

Each event defines `broadcastAs()` returning a short literal name. Echo clients listen with a leading `.` to use the literal:

```ts
echo.private(`users.${userId}`).listen('.ResumeReady', (payload) => { ... });
```

### Channel auth

`routes/channels.php`:

```php
Broadcast::channel('users.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
```

A user can only subscribe to their own channel.

---

## Queued jobs

All jobs run on the default Redis queue. Horizon supervises workers in production.

### Resume pipeline

| Job | Triggered by | Does |
|---|---|---|
| `App\Jobs\ParseResume` | `ResumeController::store`, retry button | Extract raw text (Poppler/PhpWord), call Claude for structured parse, transition status, dispatch `EmbedResume`. |
| `App\Jobs\EmbedResume` | `ParseResume` on success, retry button | Call OpenAI embeddings, write vector, dispatch `RecomputeStage1ForResume`, fire `ResumeReady`. |
| `App\Jobs\RecomputeStage1ForResume` | `EmbedResume` on success | For each search profile using this resume, `INSERT INTO job_matches SELECT ...` with pgvector cosine + hard filters, in a single SQL statement. |

### Ingestion pipeline

| Job | Triggered by | Does |
|---|---|---|
| `App\Jobs\FetchJobSource` | Scheduler, admin "Fetch now" | Resolve driver, iterate generator, upsert `canonical_jobs`, dispatch `EmbedJob` for new/changed rows. Auto-pause source after 3 consecutive failures. |
| `App\Jobs\EmbedJob` | `FetchJobSource` for new/changed jobs, retry button | Call OpenAI embeddings, write vector, dispatch `FanOutStage1Matches`. System-attributed (no cost ceiling). |
| `App\Jobs\FanOutStage1Matches` | `EmbedJob` on success | For each active search profile with a linked resume, compute cosine similarity, upsert `job_matches` row if above `stage1_score_floor`. |
| `App\Jobs\CloseStaleJobs` | Scheduler (daily) | Set `closed_at = now()` on canonical_jobs unseen for >30 days. |

### Matching

| Job | Triggered by | Does |
|---|---|---|
| `App\Jobs\RerankShortlist` | `FeedController@show` (sync), `NightlyRerankRefresh` (async), retry button | Load top-K stale matches, send to `AiClient::rerank`, write `stage2_*` columns, fire `FeedMatchReranked`. Sentinel-writes on `RerankFailed`. |
| `App\Jobs\NightlyRerankRefresh` | Scheduler (daily) | Dispatch `RerankShortlist(profile.id, 50, null)` per active profile with a linked resume. |

### Applications

| Job | Triggered by | Does |
|---|---|---|
| `App\Jobs\ApplicationReminderScan` | Scheduler (daily at `reminder_scan_hour_utc`) | Write `reminder` events for due applications; group by user; fire `ReminderBadgeUpdated`; dispatch `SendReminderEmail` for opted-in users. |
| `App\Jobs\SendReminderEmail` | `ApplicationReminderScan` | Race-check (user exists + opted in + apps still due + not yet sent today via Redis cache); send `ReminderDueMail`. |

### Retry policies

All jobs use Laravel's defaults: `$tries = 3` (except `EmbedResume` which sets `$tries = 5` since embedding can transiently fail). Exponential backoff.

Cost-ceiling and rate-limit exceptions cause the job to `release()` with a computed delay rather than fail — they're transient and will succeed after the window rolls over.

---

## Scheduled jobs

Defined in `routes/console.php`. Laravel's scheduler runs every minute (via the system cron) and evaluates each entry.

| Entry | Cadence | Dispatches |
|---|---|---|
| Ingestion gate | every minute | `FetchJobSource` per enabled source whose `last_fetched_at` is null or older than `fetch_cadence_minutes` |
| `CloseStaleJobs` | daily | maintenance |
| Nightly rerank gate | every minute, gated by hour | `NightlyRerankRefresh` when `now()->utc()->hour === nightly_rerank_hour_utc AND minute === 0` |
| Reminder scan gate | every minute, gated by hour | `ApplicationReminderScan` when `now()->utc()->hour === reminder_scan_hour_utc AND minute === 0` |

The gated-every-minute pattern (vs `->dailyAt('09:00')`) is used for the rerank/reminder hours so they can be tuned via `site_settings` without restarting the scheduler.

---

## `site_settings` keys

All editable via `/admin/settings`. Seeded by `SiteSettingsSeeder` (idempotent via `firstOrCreate`).

### Matching / rerank

| Key | Type | Default | Description |
|---|---|---|---|
| `stage1_score_floor` | string | `0.2` | Minimum cosine similarity to produce a match row. |
| `rerank_enabled` | bool | `true` | Kill-switch for stage-2 rerank. When false, feed shows stage-1 order with placeholder rationale cards. |
| `rerank_shortlist_size` | int | `20` | Top-K matches reranked on feed view. |
| `rerank_max_batch_size` | int | `20` | Max jobs per Claude rerank call; larger inputs split into sequential batches. |
| `stage2_ttl_hours` | int | `24` | Staleness threshold. Matches with `stage2_at` older than this trigger re-rerank on feed view. |
| `nightly_rerank_hour_utc` | int | `4` | UTC hour for `NightlyRerankRefresh`. |

### AI models

| Key | Type | Default | Description |
|---|---|---|---|
| `openai_embeddings_model` | string | `text-embedding-3-small` | OpenAI embedding model. |
| `anthropic_parse_model` | string | `claude-sonnet-4-6` | Claude model for resume parsing. |
| `anthropic_rerank_model` | string | `claude-sonnet-4-6` | Claude model for stage-2 rerank. |

### Cost

| Key | Type | Default | Description |
|---|---|---|---|
| `cost_ceiling_per_user_per_day_usd` | string | `5.00` | Per-user daily AI spend ceiling in USD. Checked by `AiClient::enforceCostCeiling`. |

### Reminders

| Key | Type | Default | Description |
|---|---|---|---|
| `reminder_scan_hour_utc` | int | `9` | UTC hour for daily reminder scan. |
| `nav_reminder_badge_ttl_seconds` | int | `60` | Redis cache TTL for the nav badge count per user. |

### Theming (Plan 6)

| Key | Type | Default | Description |
|---|---|---|---|
| `brand_wordmark_url_override` | string | `` | URL to a custom SVG wordmark. Empty = use `config/brand.php` default. |
| `brand_favicon_url_override` | string | `` | URL to a custom favicon. |
| `brand_color_primary_override` | string | `` | Hex color override for the ink (primary text) token. |
| `brand_color_oxblood_override` | string | `` | Hex color override for the oxblood accent. |
| `brand_font_sans_override` | string | `` | CSS font-family for body text. |

All brand overrides are honored by `BrandConfig` — empty string falls back to `config/brand.php`.

---

## Job source drivers

Eight drivers ship in v1 (3 in Plan 2, 3 in Plan 3, 2 in Plan 4). Each implements the `JobSourceDriver` contract:

```php
interface JobSourceDriver {
    public function sourceType(): string;
    public function fetch(JobSource $source): iterable;  // yields CanonicalJobData
    public function validateIdentifier(?string $identifier): bool;
}
```

### Driver summary

| Driver | `sourceType` | Identifier | Shape | Credentials |
|---|---|---|---|---|
| `GreenhouseDriver` | `greenhouse` | Board slug (e.g., `stripe`) | Per-company ATS | None |
| `LeverDriver` | `lever` | Company slug (e.g., `netflix`) | Per-company ATS | None |
| `RemoteOkDriver` | `remoteok` | null | Single-feed aggregator | None |
| `WorkableDriver` | `workable` | Company slug | Per-company ATS | None |
| `AshbyDriver` | `ashby` | Company slug | Per-company ATS | None |
| `AdzunaDriver` | `adzuna` | Country code | Paginated aggregator | `ADZUNA_APP_ID` + `ADZUNA_APP_KEY` |
| `RemotiveDriver` | `remotive` | null | Single-feed aggregator | None |
| `USAJobsDriver` | `usajobs` | null | Paginated, authenticated | `USAJOBS_AUTH_KEY` + `USAJOBS_USER_AGENT_EMAIL` |

### Upstream API shapes

Each driver normalizes a distinct upstream shape into `CanonicalJobData`. Relevant shape notes:

- **Greenhouse** — `GET /v1/boards/{slug}/jobs?content=true`. Returns `{jobs: [...]}`. Comp parsed from `metadata` entries via regex.
- **Lever** — `GET /v0/postings/{company}?mode=json`. Returns a plain array (no wrapper). `categories.location` split on `/` for multi-locations. `workplaceType` drives remote mode.
- **RemoteOK** — `GET /api`. Returns an array whose first element is a metadata/legal object; driver skips it. All jobs treated as `remote_only`.
- **Workable** — `GET /api/v1/widget/accounts/{company}?details=true`. Returns `{jobs: [...]}`. `location.telecommuting` drives remote mode.
- **Ashby** — `GET /posting-api/job-board/{company}`. Returns `{jobs: [...]}`. Comp parsed from `compensation.compensationTierSummary` via regex.
- **Adzuna** — `GET /v1/api/jobs/{country}/search/{page}?...`. Paginated. Currency auto-mapped from country (14 markets: USD / GBP / AUD / CAD / EUR / PLN / INR / NZD / ZAR / SGD / CHF / MXN / BRL / RUB).
- **Remotive** — `GET /api/remote-jobs`. Returns `{jobs: [...]}` with a legal-notice key at position `0-legal-notice`. All jobs `remote_only`.
- **USAJobs** — `GET /api/search?Page=N`. Paginated. Returns `{SearchResult: {SearchResultItems: [{MatchedObjectId, MatchedObjectDescriptor}]}}`. Requires `Authorization-Key` + `User-Agent` with contact email.

### Adding a new driver

See [architecture.md — the ingestion pipeline](architecture.md#the-ingestion-pipeline). Brief:

1. Create `app/Services/Jobs/Drivers/YourDriver.php` implementing `JobSourceDriver`.
2. Register in `config/throughline.php` under `drivers`.
3. Write unit tests with fixtures under `tests/Fixtures/drivers/your-driver/`.
4. Seed a `job_sources` row via the admin UI or seeder.

The contract is: drivers yield `CanonicalJobData` DTOs. They don't touch Eloquent. `FetchJobSource` handles upsert.

---

## Environment variables

Full reference. Bold = required for basic operation.

### App

- **`APP_NAME`** — `Throughline`
- **`APP_ENV`** — `local`, `testing`, or `production`
- **`APP_KEY`** — 32-byte base64 key, generated via `php artisan key:generate`
- `APP_DEBUG` — `true` in dev, `false` in prod
- **`APP_URL`** — full URL (with scheme)

### Database

- **`DB_CONNECTION`** — `pgsql`
- **`DB_HOST`**
- **`DB_PORT`** — `5432`
- **`DB_DATABASE`** — `throughline` in dev, your prod DB name
- **`DB_USERNAME`**
- **`DB_PASSWORD`**

### Redis

- **`REDIS_HOST`**
- **`REDIS_PORT`** — `6379`
- `REDIS_PASSWORD` — `null` if unauth'd, otherwise the password

### Queue / cache / session / broadcast

- **`QUEUE_CONNECTION`** — `redis` in prod, `sync` in testing, `redis` or `database` in dev
- **`CACHE_STORE`** — `redis` (or `array` in tests)
- **`SESSION_DRIVER`** — `redis` in prod, `file` locally if Redis is flaky
- `BROADCAST_CONNECTION` — `reverb` with Reverb running, `log` otherwise

### Mail

- **`MAIL_MAILER`** — `log` in dev, your provider in prod
- `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` — SMTP settings
- `POSTMARK_TOKEN` — if using Postmark
- `RESEND_KEY` — if using Resend
- **`MAIL_FROM_ADDRESS`**
- **`MAIL_FROM_NAME`**

### S3 / filesystem

- `FILESYSTEM_DISK` — `local` in dev, `s3` in prod
- `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`
- `AWS_ENDPOINT` — for non-AWS S3-compatible (DO Spaces, R2, etc.)

### AI

- **`OPENAI_API_KEY`** — for embeddings
- `OPENAI_ORGANIZATION` — optional, for team accounts
- **`ANTHROPIC_API_KEY`** — for parse + rerank

### OAuth

- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`
- `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET`, `GITHUB_REDIRECT_URI`

### Drivers

- `ADZUNA_APP_ID`, `ADZUNA_APP_KEY`
- `ADZUNA_MAX_PAGES` — default `20`
- `ADZUNA_RESULTS_PER_PAGE` — default `50`
- `USAJOBS_AUTH_KEY`, `USAJOBS_USER_AGENT_EMAIL`
- `USAJOBS_MAX_PAGES` — default `10`
- `USAJOBS_RESULTS_PER_PAGE` — default `25`

### Reverb

- `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`
- `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`
- `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` — compiled into the JS bundle at Vite build time

### PDF text extraction

- `PDFTOTEXT_BINARY` — explicit path to `pdftotext` if not on PATH (Windows typically needs this)

### Misc

- `HORIZON_ENVIRONMENT` — defaults to `APP_ENV`
- `PULSE_ENABLED` — `true` to enable Laravel Pulse (not installed in v1)
- `TELESCOPE_ENABLED` — `true` to enable Telescope (not installed in v1)
- `THROUGHLINE_UPSTREAM_DRIFT_TEST` — `1` to enable the nightly drift test against real Greenhouse API

---

## Admin routes

All under `/admin` prefix, gated by `auth + EnsureAdmin` middleware.

```
GET    /admin                                           admin.overview
GET    /admin/users                                     admin.users.index
GET    /admin/users/{user}                              admin.users.show
PATCH  /admin/users/{user}                              admin.users.update
POST   /admin/impersonate/{user}/start                  admin.impersonate.start
GET    /admin/jobs                                      admin.jobs.index
GET    /admin/jobs/{canonical_job}                      admin.jobs.show
GET    /admin/job-sources                               admin.job-sources.index
GET    /admin/job-sources/create                        admin.job-sources.create
POST   /admin/job-sources                               admin.job-sources.store
GET    /admin/job-sources/{job_source}/edit             admin.job-sources.edit
PATCH  /admin/job-sources/{job_source}                  admin.job-sources.update
DELETE /admin/job-sources/{job_source}                  admin.job-sources.destroy
POST   /admin/job-sources/{job_source}/fetch-now        admin.job-sources.fetch-now
GET    /admin/analytics                                 admin.analytics
GET    /admin/settings                                  admin.settings.index
PATCH  /admin/settings/{key}                            admin.settings.update
GET    /admin/audit                                     admin.audit.index
GET    /admin/errors                                    admin.errors
POST   /admin/errors/resumes/{resume}/retry-parse       admin.errors.resume.retry-parse
POST   /admin/errors/resumes/{resume}/retry-embed       admin.errors.resume.retry-embed
POST   /admin/errors/jobs/{canonical_job}/retry-embed   admin.errors.job.retry-embed
POST   /admin/errors/matches/{match}/retry-rerank       admin.errors.match.retry-rerank
```

Plus:
- `POST /impersonate/stop` (note: outside /admin prefix, gated by `auth` only) — `admin.impersonate.stop`
- `GET /horizon/*` — Laravel Horizon routes, gated by the `viewHorizon` Gate

---

## User-facing routes

All under `auth` middleware (except the auth-related routes).

### Auth

```
GET    /login                           login
POST   /login                           login (store)
GET    /register                        register
POST   /register                        register (store)
POST   /logout                          logout
GET    /auth/{provider}/redirect        auth.social.redirect  (Google, GitHub)
GET    /auth/{provider}/callback        auth.social.callback
```

### Resumes

```
GET    /resumes                         resumes.index
POST   /resumes                         resumes.store
GET    /resumes/{resume}                resumes.show
PATCH  /resumes/{resume}                resumes.update
DELETE /resumes/{resume}                resumes.destroy
POST   /resumes/{resume}/default        resumes.default
POST   /resumes/{resume}/retry          resumes.retry
```

### Search profiles

```
GET    /search-profiles                 search-profiles.index
GET    /search-profiles/create          search-profiles.create
POST   /search-profiles                 search-profiles.store
GET    /search-profiles/{profile}/edit  search-profiles.edit
PUT    /search-profiles/{profile}       search-profiles.update
DELETE /search-profiles/{profile}       search-profiles.destroy
```

### Feed

```
GET    /feed/{profile}                  feed.show
```

### Applications

```
GET    /applications                                  applications.index  (kanban)
POST   /applications                                  applications.store  (save from feed)
GET    /applications/{application}                    applications.show
PATCH  /applications/{application}                    applications.update  (status/notes/next_action_at)
DELETE /applications/{application}                    applications.destroy  (soft-withdraw)
POST   /applications/{application}/contacts           applications.contacts.store
PATCH  /applications/{application}/contacts/{contact} applications.contacts.update
DELETE /applications/{application}/contacts/{contact} applications.contacts.destroy
POST   /applications/{application}/documents          applications.documents.store
DELETE /applications/{application}/documents/{document} applications.documents.destroy
```

### Broadcasting (for Echo)

```
POST   /broadcasting/auth                authenticate WebSocket channel subscriptions
```

---

## Database schema summary

See the [ER diagram in architecture.md](architecture.md#data-model) for the visual. Tables:

- `users` — accounts
- `social_accounts` — OAuth identities linked to users
- `resumes` — PDF/DOCX + parsed_text + structured_json + embedding vector(1536)
- `search_profiles` — per-user search criteria + hard filters
- `canonical_jobs` — global jobs ingested from drivers; embedding vector(1536)
- `job_sources` — driver configuration (which board/company/feed to fetch)
- `job_matches` — per-user pairing with stage-1 cosine + stage-2 Claude output
- `applications` — saved jobs in 8-status kanban
- `application_events` — append-only audit trail
- `application_contacts` — recruiters/hiring-managers/etc.
- `application_documents` — attachments
- `site_settings` — admin-tunable runtime config
- `ai_usage_events` — every OpenAI/Anthropic call
- `admin_activity_log` — append-only admin audit
- `failed_jobs` — Laravel's failed queue jobs (Horizon UI surfaces this)
- `cache`, `cache_locks`, `sessions`, `jobs` — Laravel internals
- `horizon_*` — Horizon's own tables

---

## Glossary

- **Stage-1 match** — cosine similarity between resume embedding and job embedding, computed during `FanOutStage1Matches`.
- **Stage-2 rerank** — Claude's per-job assessment (fit score + rationale + gaps + narrative), stored on the same `job_matches` row as stage-1.
- **Shortlist** — the top-K stale matches (default 20) that `RerankShortlist` sends to Claude.
- **`stage2_snapshot`** — the rerank output preserved on an `applications` row at save-time, so later reranks can't retcon saved history.
- **Sentinel rerank** — when Claude returns unparseable output, `RerankShortlist` writes `stage2_rationale = 'rerank unavailable'` on affected rows to prevent retry loops.
- **Terminal statuses** — `offer`, `rejected`, `withdrawn`. Applications in these states are collapsed in the kanban UI.
- **Acting user** vs **system call** — `AiClient::forUser($user)` writes the user id to `ai_usage_events`; `forUser(null)` (used by `EmbedJob` + nightly rerank) is "system" and bypasses the cost ceiling.
- **Fan-out** — the process of inserting `job_matches` rows across all eligible search profiles when a new job (or resume) becomes available.
