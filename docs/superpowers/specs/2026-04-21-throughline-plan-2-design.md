# Throughline — Plan 2 design (resumes + search profiles + stage-1 matching)

**Status:** Design spec. Superseded by implementation plan once written.

**Main product spec:** [`2026-04-21-throughline-design.md`](./2026-04-21-throughline-design.md). This document is a Plan-2 scope sub-spec; nothing here contradicts the product spec, and references to module shape, table columns, and flow names use the product-spec vocabulary.

**Plan 1 (Foundation)** shipped. This document scopes **Plan 2 of 6**: the first slice that brings job-matching end-to-end, minus Claude rerank.

---

## 1. Scope & intent

### What Plan 2 delivers (user-visible)

A signed-in user can:

- Upload one or more named resumes (PDF or DOCX). Each is parsed to structured JSON via Claude and embedded via OpenAI.
- Mark one resume as default; rename and delete resumes.
- Create one or more search profiles, each optionally tied to a resume, with all hard-filter fields the product spec defines (role keywords, locations, remote mode, comp floor, industries, seniority, excluded companies).
- View a per-profile feed of ranked jobs, ordered by cosine similarity of the profile's linked resume embedding against `jobs.embedding`, bounded by the profile's hard filters.
- See each ranked job rendered in the Plan-3 card shell with company, title, location summary, remote-mode badge, comp range, posted date — and a placeholder "AI rationale pending" section where Plan 3's rerank output will land.

Admins can:

- CRUD `job_sources` rows for three driver types — Greenhouse (per-company-board ATS), Lever (per-company ATS), and RemoteOK (single-row aggregator).
- Enable/disable a source, set its fetch cadence, trigger an on-demand "fetch now", and see `last_fetched_at` + `last_error` inline.

### What Plan 2 does NOT deliver

- Stage-2 Claude rerank — fit score, prose rationale, structured gaps, narrative subtitle (Plan 3).
- Nightly rerank refresh job (Plan 3).
- Remaining five drivers from the product spec's starter set: Workable, Ashby, Adzuna, Remotive, USAJobs (Plan 3 or later).
- Application pipeline: `applications`, `application_events`, `application_contacts`, `application_documents`, kanban UI, reminders (Plan 4).
- Full admin dashboard surface: Overview, Users + impersonation, Jobs browser, Analytics with cost charts, Settings editor, Horizon mount, Error log, Audit log viewer (Plan 5).
- Laravel Reverb real-time push. UI uses polling where freshness matters (the resume-ready indicator on the resume detail page). Reverb is Plan 6.
- The upstream-drift nightly test from product spec §7 — stub only, gated by env var, not run.

### Sequencing principle

Infrastructure proven before dependent features (the "inside-out" approach). Order within the plan:

1. Dockerized Postgres 16 + pgvector replaces Windows-native Postgres 18. `compose.yaml` added; `.env.example` updated.
2. `AiClient` wrapper with Redis token bucket, per-user daily cost ceiling, and the `ai_usage_events` audit table.
3. Queue wiring (Horizon config for prod, existing `queue:listen` for Windows dev).
4. Resume module — schema, upload, parse, embed, UI.
5. Search-profile module — schema + CRUD UI.
6. Job-source infrastructure — `JobSourceDriver` interface, `DriverRegistry`, `CanonicalJob` DTO.
7. Drivers, one at a time: Greenhouse → Lever → RemoteOK. Each ships with its unit test and fixture before the next starts.
8. `jobs` + `job_sources` schema, `FetchJobSource` + `EmbedJob` queued jobs.
9. `job_matches` schema + `FanOutStage1Matches` + `RecomputeStage1ForResume`.
10. Feed UI with Plan-3-shaped card + placeholder rationale slot.
11. Admin job-sources page.
12. E2E smoke + CLAUDE.md + README updates.

---

## 2. Architecture additions

Four new module surfaces introduced in Plan 2. Each has a single responsibility and a narrow public interface so Plan 3-6 extensions don't reshape existing call sites.

### 2.1 `App\Services\Ai\AiClient`

Singleton service fronting OpenAI and Anthropic. Consumers never hold a provider SDK instance directly.

- Methods: `forUser(?User $user): self`, `embed(string $text, string $context): array` (returns `float[1536]`), `parseResume(string $rawText): array` (returns `['text' => ..., 'structured' => ...]`).
- Internals: per-provider Redis-backed token bucket, exponential-backoff retry honoring provider rate-limit headers, per-user daily cost ceiling check (`site_settings.cost_ceiling_per_user_per_day_usd` compared to `SUM(estimated_cost_usd)` from `ai_usage_events` for `(user_id, today)`), and a single `ai_usage_events` write per call.
- Attribution model: `forUser(null)->embed(...)` for system calls (ingestion). `forUser($user)->embed(...)` for user-attributed calls (resume). No thread-local or implicit context — queued jobs pass the user explicitly.
- Typed exceptions: `CostCeilingExceeded` (carries user id, current spend, ceiling), `ProviderRateLimited` (carries retry-after seconds), `ParseFailed` (Claude returned unparseable JSON). Provider SDK exceptions bubble up untouched when retry budget is exhausted.

### 2.2 `App\Contracts\Jobs\JobSourceDriver` + `App\Services\Jobs\DriverRegistry`

A PHP interface + a registry resolving `source_type → driver instance`.

- Interface methods: `sourceType(): string`, `fetch(JobSource $source): iterable` (yields `CanonicalJob`), `validateIdentifier(string $identifier): bool` (synchronous check used by the admin "create source" form).
- `fetch()` returns a generator so paginated sources don't hold full boards in memory.
- `DriverRegistry` reads `config('throughline.drivers')` — a map of source-type slugs to driver class names. Plan 2 ships with three entries; adding a driver in a later plan is a one-line config change.
- Drivers throw `DriverFetchFailed` (typed, wraps upstream HTTP/parse errors with context suitable for admin `last_error` display).

### 2.3 Queued job classes

One class per pipeline step, under `App\Jobs`. All idempotent on their natural key:

- `ParseResume(int $resumeId)` — Claude parse → `parsed_text` + `structured_json` + `parse_status`.
- `EmbedResume(int $resumeId)` — OpenAI embedding → `resumes.embedding`; dispatches `RecomputeStage1ForResume`.
- `RecomputeStage1ForResume(int $resumeId)` — for each active `search_profile` using this resume, delete-and-reinsert `job_matches` rows (bounded by hard filters, scored against every job with a non-null embedding).
- `FetchJobSource(int $jobSourceId)` — driver fetch → upsert `jobs` on `(source, source_id)` → dispatch `EmbedJob` per new/changed row.
- `EmbedJob(int $jobId)` — OpenAI embedding → `jobs.embedding`; dispatches `FanOutStage1Matches`.
- `FanOutStage1Matches(int $jobId)` — for each active `search_profile`, if its linked resume has an embedding and the job passes the profile's hard filters, insert a `job_matches` row with stage-1 score above `site_settings.stage1_score_floor` (default 0.2).
- `CloseStaleJobs` — nightly maintenance, sets `closed_at = now()` on jobs whose `last_seen_at` is older than 30 days.

Queue: all on Redis default queue. Horizon config for prod. Dev uses `queue:listen` (existing Windows ext-pcntl constraint).

### 2.4 Resume, search-profile, feed, admin Inertia modules

- `App\Http\Controllers\Resumes\ResumeController` — `index`, `store`, `update`, `destroy`, `setDefault`.
- `App\Http\Controllers\SearchProfiles\SearchProfileController` — standard CRUD.
- `App\Http\Controllers\FeedController` — `show(SearchProfile $profile)` renders feed.
- `App\Http\Controllers\Admin\JobSourceController` — CRUD + `fetchNow(JobSource $source)` action.
- Vue pages: `resources/js/pages/resumes/{Index,Show,Edit}.vue`, `resources/js/pages/search-profiles/{Index,Edit}.vue`, `resources/js/pages/feed/Show.vue`, `resources/js/pages/admin/job-sources/{Index,Edit}.vue`.
- Shared feed component: `resources/js/components/feed/JobMatchCard.vue` — renders Plan-3-shaped card with a `<RationaleSlot>` child currently showing placeholder copy. Plan 3 replaces the slot's body without touching the card layout.

### 2.5 Module boundaries

- Drivers do not know about matching. Matching does not know about drivers.
- Feed reads pre-computed `job_matches` rows; never calls matching or embedding code synchronously.
- Every vector lives on one entity table only — resume vectors on `resumes`, job vectors on `jobs`. No shared vector store.

---

## 3. Data model additions

Six migrations in Plan 2, ordered to respect FKs. Table shapes come from the product spec; notes here are Plan-2-specific decisions.

### 3.1 Migration order

1. **`enable_pgvector_extension`** — `CREATE EXTENSION IF NOT EXISTS vector;` Separate migration for clean rollback and deterministic fresh-DB behavior.
2. **`create_resumes_table`** — product-spec shape. `embedding vector(1536)` nullable (populated by `EmbedResume` after parse). Partial unique index `WHERE is_default = true` scoped by `user_id` ensures at most one default per user. `user_id` FK cascade-delete.
3. **`create_job_sources_table`** — `(source_type, identifier)` unique. `display_name` required. `fetch_cadence_minutes` default 60. `is_enabled` default true. Seeded by `JobSourcesSeeder`.
4. **`create_jobs_table`** — product-spec shape. `(source, source_id)` unique. `embedding vector(1536)` nullable. `locations jsonb`, `raw_payload jsonb`. Indexes: `posted_at DESC` for recency queries; `ivfflat` vector index on `embedding` created immediately (small-table penalty negligible, removes a "don't forget later" footgun).
5. **`create_search_profiles_table`** — product-spec shape. `resume_id` FK nullable, `ON DELETE SET NULL` (deleting a resume doesn't cascade-nuke profiles). All list fields stored as `jsonb`.
6. **`create_job_matches_table`** — product-spec shape. `(search_profile_id, job_id)` unique for idempotent fan-out. `stage2_*` columns nullable. Indexes: `(user_id, dismissed_at, stage1_score DESC)` for the feed, `(search_profile_id, stage1_score DESC)` for per-profile recompute.
7. **`create_ai_usage_events_table`** — product-spec shape. Written exclusively by `AiClient`. Indexes: `(user_id, created_at DESC)` for per-user cost aggregation in the ceiling check, `(provider, model, created_at DESC)` for Plan-5 admin charts.

### 3.2 Product-spec deltas

- **`longtext` → `text`** everywhere. `longtext` is MySQL-only; Postgres `text` is unlimited. Applies to `jobs.description` and `resumes.parsed_text`.
- **`resumes.embedding` nullable tolerated by the matcher.** `FanOutStage1Matches` skips pairs where either side's embedding is null (no error, no row inserted). Successor jobs (`EmbedResume`, `EmbedJob`) retrigger fan-out when an embedding lands.

### 3.3 Seed data

- `JobSourcesSeeder` inserts a curated starter list of Greenhouse boards, Lever companies, and the single RemoteOK aggregator row. Representative seeds are chosen to cover comp-present vs comp-absent, on-site vs remote, US vs international — so local dev against a real fetch exercises the normalization edge cases.
- `SiteSettingsSeeder` (from Plan 1) gains new keys: `stage1_score_floor` (default `0.2`), `cost_ceiling_per_user_per_day_usd` (default `5.00`), `openai_embeddings_model` (default `text-embedding-3-small`), `anthropic_parse_model` (default `claude-sonnet-4-6`).

---

## 4. Data flow

Three happy paths Plan 2 makes work end-to-end. Every step is idempotent; retries never duplicate rows or double-count cost.

### 4.1 Flow A — Resume upload

1. User POSTs PDF/DOCX → `ResumeController@store` stores the file on the configured disk (local in dev, S3-compatible in prod), inserts a `resumes` row with `parse_status = pending`, `embedding = null`, then dispatches `ParseResume`.
2. `ParseResume` extracts raw text (Poppler shell-out for PDF; `phpoffice/phpword` for DOCX), calls `AiClient::forUser($user)->parseResume($rawText)`, writes `parsed_text` + `structured_json` + `parse_status = parsed`, then dispatches `EmbedResume`.
3. `EmbedResume` calls `AiClient::forUser($user)->embed($parsedText, 'resume')`, writes `resumes.embedding`, dispatches `RecomputeStage1ForResume`.
4. `RecomputeStage1ForResume` iterates `search_profiles` where `resume_id = $resumeId`. For each profile, deletes `job_matches` rows scoped to `(search_profile_id = $profile->id)`, then iterates all jobs with a non-null embedding, applies the profile's hard filters, computes cosine similarity, and inserts rows scoring above `stage1_score_floor`. The existing Plan-3 `stage2_*` columns on deleted rows are sacrificed — this is acceptable in Plan 2 since stage-2 doesn't exist yet; Plan 3 will need to revisit the delete-and-reinsert pattern to preserve rerank output.
5. UI polls `GET /resumes/{resume}` (JSON response) until `parse_status = parsed` AND `embedding IS NOT NULL`.

### 4.2 Flow B — Ingestion

1. Scheduler (in `routes/console.php`) dispatches `FetchJobSource` per enabled `job_sources` row on its `fetch_cadence_minutes`.
2. `FetchJobSource` resolves the driver via `DriverRegistry`, iterates the driver's `fetch()` generator, upserts each `CanonicalJob` into `jobs` on `(source, source_id)`. Existing records get `last_seen_at` refreshed.
3. For each new or changed job, `EmbedJob` is dispatched. `EmbedJob` calls `AiClient::forUser(null)->embed($description, 'job')` — system-attributed, `ai_usage_events.user_id = NULL`.
4. After an embedding lands, `FanOutStage1Matches` is dispatched scoped to that single job id. It iterates active `search_profiles`, applies hard filters as a SQL WHERE, computes `1 - (resume.embedding <=> job.embedding)` (pgvector cosine distance → similarity), and inserts a `job_matches` row if the similarity exceeds `stage1_score_floor`.
5. Nightly `CloseStaleJobs` sets `closed_at = now()` on jobs unseen for 30 days.

### 4.3 Flow C — Feed view

1. User opens `/feed/{searchProfile}`. `FeedController@show` queries `job_matches` joined to `jobs` where `search_profile_id = ?` AND `dismissed_at IS NULL`, ordered by `stage1_score DESC`, paginated (20/page).
2. Inertia response carries an array of `JobMatchCard` view-models. The Vue page renders `<JobMatchCard>` per row.
3. Each card shows company, title, location summary, remote-mode badge, comp range (if present), posted-date, and an always-visible `<RationaleSlot>` child component currently rendering placeholder copy ("Career-arc rationale ships in Plan 3"). Stage-1 score is visible only when an admin-only debug flag is set on the request.
4. No stage-2 code path exists in Plan 2. The rationale slot is static.

### 4.4 Failure modes (reference)

- `ParseResume` → `ParseFailed` → `parse_status = parse_failed`; raw extracted text saved to `parsed_text` anyway so the manual-paste fallback can show the user what the extraction produced. UI offers retry button that re-dispatches.
- `EmbedResume` → `CostCeilingExceeded` → job released with `delay = midnight - now()` (retries after the ceiling window rolls over). `ProviderRateLimited` → job released with `delay = retryAfter`. Other exceptions follow Horizon's policy (3 attempts, exponential backoff).
- `EmbedJob` → same handling minus `CostCeilingExceeded` (system calls don't trip the ceiling).
- Driver `fetch()` → `DriverFetchFailed` → `job_sources.last_error` updated with exception summary + timestamp; auto-pause after 3 consecutive schedule-level failures.
- `FanOutStage1Matches` iterates profiles in an exception-isolated loop; one profile's failure doesn't abort the fan-out for others.

---

## 5. Key interfaces

### 5.1 `AiClient`

```php
namespace App\Services\Ai;

class AiClient
{
    public function forUser(?User $user): self;
    public function embed(string $text, string $context): array;
    public function parseResume(string $rawText): array;
}
```

Contexts used in Plan 2: `'resume'`, `'job'`. Plan 3 adds `'rerank_batch'` + a `rerank()` method. Exceptions: `CostCeilingExceeded`, `ProviderRateLimited`, `ParseFailed`.

### 5.2 `JobSourceDriver`

```php
namespace App\Contracts\Jobs;

interface JobSourceDriver
{
    public function sourceType(): string;
    public function fetch(JobSource $source): iterable;
    public function validateIdentifier(string $identifier): bool;
}
```

Implementations: `GreenhouseDriver`, `LeverDriver`, `RemoteOkDriver` under `App\Services\Jobs\Drivers\`. Exception: `DriverFetchFailed`.

### 5.3 `CanonicalJob` DTO

```php
namespace App\DTO;

readonly class CanonicalJob
{
    public string $sourceId;
    public string $sourceBoard;
    public string $companyName;
    public string $title;
    public string $description;
    public ?string $locationRaw;
    public array $locations;
    public string $remoteMode;
    public ?int $compMin;
    public ?int $compMax;
    public ?string $compCurrency;
    public ?string $seniority;
    public ?string $employmentType;
    public string $applyUrl;
    public \DateTimeImmutable $postedAt;
    public array $rawPayload;
}
```

`FetchJobSource` upserts these into `jobs` unchanged. Nullable fields (`compMin`, `compMax`, etc.) handled correctly by the matcher's hard filters.

---

## 6. Error handling & testing strategy

### 6.1 Error-handling rules

- Typed exceptions only for known failure modes. Provider SDK exceptions bubble up untouched after retry budget exhausted.
- Parse failures leave `parsed_text` populated with the raw extraction so the user's manual-paste recovery has context.
- Cost-ceiling retries time-shift to next UTC midnight.
- Per-profile failure in a fan-out loop never aborts the whole loop.
- Driver auto-pause is configuration, not code — `job_sources.is_enabled = false` set by the scheduler after 3 consecutive failures, admin can re-enable.

### 6.2 Feature tests (new in Plan 2)

- `ResumeUploadTest` — upload → queued jobs → parse_status transitions → embedding populated. Uses a test double for `AiClient` returning deterministic vectors. Includes parse-fail path with manual-paste recovery.
- `SearchProfileCrudTest` — full CRUD, including FK-nullable behavior when the linked resume is deleted.
- `FeedTest` — seeded resume embedding + 3 seeded jobs with known vectors → assert feed order matches cosine-similarity math. Validates card render including the placeholder rationale slot.
- `AdminJobSourcesTest` — create/enable/disable/delete/fetch-now flow; `last_error` rendering.

### 6.3 Driver unit tests

One per driver, each loading recorded JSON fixtures into `Http::fake()`:

- `GreenhouseDriverTest`, `LeverDriverTest`, `RemoteOkDriverTest`.
- Fixtures: `tests/Fixtures/drivers/{driver}/{scenario}.json`.
- At least one happy-path fixture and one edge case per driver (Greenhouse: job with no comp info; Lever: posting with multiple office locations; RemoteOK: posting with `null` company_logo).

### 6.4 Matching tests

- `FanOutStage1MatchesTest` — fake deterministic embeddings verify cosine ordering and hard-filter exclusions (remote-only profile excludes on-site jobs; comp floor excludes low-comp jobs; excluded-company name-contains excludes correctly).

### 6.5 AiClient tests

- `AiClientCostCeilingTest` — second call after hitting ceiling throws `CostCeilingExceeded`; `ai_usage_events` reflects all attempted calls.
- `AiClientRateLimiterTest` — Redis token bucket blocks when provider limit approached.

### 6.6 E2E smoke

One Playwright happy path: sign in → upload fixture resume → wait for parse indicator → create search profile → inject 3 fake jobs via a test-only route (guarded by `APP_ENV === 'testing'`) → see ranked feed with placeholder rationale. No external API calls; `AiClient` is bound to a fake provider in the testing service provider.

### 6.7 Hardcoded-hosts guard

Extends existing `NoHardcodedHostsTest` to forbid literal upstream driver URLs (`boards-api.greenhouse.io`, `api.lever.co`, `remoteok.com`) outside `config/services.php` and the driver classes themselves.

---

## 7. Preconditions, setup, and docs updates

### 7.1 Dev-environment changes

1. **`compose.yaml`** (new, repo root) — services: `postgres` (image `pgvector/pgvector:pg16`, port 5432, named volume), `redis` (image `redis:7-alpine`, port 6379). `.env.example` updated: `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_DATABASE=throughline`, `DB_USERNAME=throughline`, `DB_PASSWORD=throughline`.
2. **`composer run dev`** unchanged — PHP + queue listener + Vite. Devs run `docker compose up -d` once per session.
3. **Herd `pdo_pgsql`** — if not available, explicit Herd PHP path (`C:/Users/david/.config/herd/bin/php84/php.exe`) remains the fallback for artisan. Documented in CLAUDE.md quirks.
4. **Horizon** — config added, `.env.example` flagged `HORIZON_ENVIRONMENT=local`. Dev still uses `queue:listen`.
5. **Ziggy** — new routes (`resumes.*`, `search-profiles.*`, `feed.*`, `admin.job-sources.*`) auto-included.

### 7.2 CLAUDE.md updates (bundled into Plan 2's docs task)

- Stack line confirms PostgreSQL 16 via Docker image `pgvector/pgvector:pg16`; Windows-native PG 18 abandoned.
- New dev-loop note: "Run `docker compose up -d` before `composer run dev`. pgvector is created automatically by the first migration."
- New commands section: `docker compose up -d` / `docker compose down` / `docker compose logs -f postgres`.
- Three new architecture bullets: `AiClient` as single AI-call surface, `JobSourceDriver` + registry, per-entity vector storage (no shared vector table).

### 7.3 Preconditions for Plan 2 Task 1

- Docker Desktop 4.30+ on PATH (`docker --version`).
- Existing Herd PHP 8.4.
- Existing Node 20+.

No new global installs.

---

## 8. Deferred to Plan 3 and beyond

Explicit scope fence:

- **Plan 3:** stage-2 Claude rerank (fit score, rationale, gaps, narrative), `NightlyRerankRefresh`, drivers 4-8 (Workable, Ashby, Adzuna, Remotive, USAJobs), activation of the upstream-drift nightly test.
- **Plan 4:** application pipeline (`applications`, `application_events`, `application_contacts`, `application_documents`), kanban UI, `ApplicationReminderScan`.
- **Plan 5:** full admin dashboard — Overview, Users + impersonation, Jobs browser, Analytics with cost charts, Settings editor with secret masking, Horizon mount, Error log, Audit log viewer.
- **Plan 6:** polish, Reverb real-time push, pgvector `ivfflat` index retuning once data volume supports it, brand-string leak test, settings-driven theming overrides.

---

## 9. Definition of done for Plan 2

- A fresh clone + `docker compose up -d` + `composer install` + `npm install` + `.env` setup + `php artisan migrate --seed` + `composer run dev` yields a running app with the three seeded drivers visible in `/admin/job-sources`.
- A user can sign in, upload a resume, create a search profile, and see a ranked feed within a few minutes of first login (driver fetch cadence dependent).
- All feature tests, driver unit tests, matching tests, AiClient tests, and the Playwright smoke pass in CI.
- CLAUDE.md and README reflect the Docker-based dev loop.
- The `ai_usage_events` table is populated with real rows from a manual smoke test.
