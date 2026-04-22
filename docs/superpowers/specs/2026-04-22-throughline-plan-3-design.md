# Throughline — Plan 3 design (stage-2 rerank + ingestion expansion)

**Status:** Design spec.

**Main product spec:** [`2026-04-21-throughline-design.md`](./2026-04-21-throughline-design.md). **Plan 2 design:** [`2026-04-21-throughline-plan-2-design.md`](./2026-04-21-throughline-plan-2-design.md).

This document scopes **Plan 3 of 6** — the stage-2 rerank that turns Plan 2's bare ranked list into the narrative-aware career-fit feed that is Throughline's product differentiator.

---

## 1. Scope & intent

### What Plan 3 delivers (user-visible)

A signed-in user opens their search-profile feed and every top-K card now carries:

- A **fit score** (int 0-100) produced by Claude, independent of the stage-1 cosine score.
- A **one-sentence narrative** that frames *how this role fits the candidate's career arc* — rendered as the card subtitle, replacing the Plan 2 placeholder.
- A **prose rationale** (2-4 sentences) explaining the fit, revealed when the card is expanded.
- A **structured gaps list** — an array of `{ requirement: string, severity: "blocker" | "stretch" | "minor" }` entries that the user has to close or accept. Gaps render as tinted chips inline in the expanded card.

The fit score, not the stage-1 cosine, drives the default feed order (users can toggle back to stage-1 order via a URL param that admins expose during debugging).

A nightly job keeps the top-50 matches per active profile "fresh" — a user opening their feed the next morning sees stage-2 output on the first K jobs without waiting for a synchronous Claude call.

### What Plan 3 ALSO delivers (plumbing users don't see directly)

- **Three more drivers** — Workable, Ashby, Adzuna — broadening the job pool. Workable and Ashby extend the ATS shape from Plan 2's Greenhouse/Lever drivers. Adzuna introduces a genuinely new shape: a paginated, country-scoped aggregator API, which stresses `JobSourceDriver::fetch()`'s generator contract in a way neither Greenhouse nor Lever did. (RemoteOK is a single-page aggregator; Adzuna paginates.)
- **Upstream-drift nightly test activation** — the `JobSourcesUpstreamDriftTest` stub Plan 2 Task 15 scaffolded is wired to run nightly against Stripe's Greenhouse board as a canary, gated by `THROUGHLINE_UPSTREAM_DRIFT_TEST=1` so it never runs on PR CI.

### What Plan 3 does NOT deliver

- **Application pipeline** (applications, events, contacts, documents, kanban UI, reminders). Plan 4.
- **Remotive and USAJobs drivers.** Plan 4+ — Adzuna proves the paginated-aggregator shape, so the remaining two drivers only add coverage, not contract insight.
- **Full admin dashboard** (overview, user impersonation, analytics charts, cost charts, audit log viewer). Plan 5.
- **Per-job "save" action** — the feed card has no save button yet. That's Plan 4's entry into application tracking.
- **Embedding-model swaps, alternative reranker providers, multi-model A/B.** Plan 6+.

### Sequencing principle

Backend before UI, rerank before expansion, proven-in-tests before wired-to-users. Concretely:

1. Housekeeping first (gitignore, Playwright selector fix, upstream-drift test activation) — clears the decks and proves Plan 2's E2E is now exercisable end-to-end.
2. `AiClient::rerank()` lands with full unit-test coverage before any job or controller calls it.
3. `RerankShortlist` queued job (sync-dispatchable for feed views, async for nightly) lands with tests before the feed controller integrates it.
4. Feed controller integration — synchronous rerank of top-K on feed view when stage-2 data is stale, graceful degrade on failure.
5. `NightlyRerankRefresh` scheduled job — the last rerank-side piece.
6. Drivers last — they broaden the job pool but aren't on the rerank critical path, so any problem there doesn't block the marquee feature.

---

## 2. Architecture additions

### 2.1 `AiClient::rerank()`

New method on the existing `AiClient` singleton. Signature:

```php
public function rerank(string $resumeText, array $jobs): array;
```

- `$jobs` is a list of `['id' => int, 'title' => string, 'company' => string, 'description' => string]` entries — the top-K shortlist assembled by the caller from `canonical_jobs`.
- Returns an array of `['job_id' => int, 'fit_score' => int, 'rationale' => string, 'gaps' => [{'requirement' => string, 'severity' => string}], 'narrative' => string]` entries, one per input job. Claude is prompted with strict structured-output instructions; `AiClient` validates the response shape and throws `RerankFailed` if it doesn't parse or any required field is missing for any job.
- Same cost-ceiling + rate-limiter surface as existing `embed()` / `parseResume()`. Attribution via the existing `forUser()` pattern — rerank called from a feed view uses the viewing user; nightly rerank uses null (system-attributed).
- Writes one `ai_usage_events` row per call with `operation = 'rerank'`, `context_type = 'rerank_batch'`, and a new `context_id` populated with the search profile id (so Plan 5's admin analytics can join cost by profile).
- **Model**: `claude-sonnet-4-6` by default, configurable via `site_settings.anthropic_rerank_model`. Note the deliberate separation from `anthropic_parse_model` — rerank and parse are different workloads and cost profiles.

### 2.2 `RerankShortlist` queued job

```php
new RerankShortlist(int $searchProfileId, int $shortlistSize, ?int $actingUserId = null);
```

- Queries `job_matches` for the given profile, orders by `stage1_score DESC`, takes top `$shortlistSize`, filters out rows whose `stage2_at` is newer than `stage2_ttl_hours` (new site setting, default 24h). Whatever's left is the shortlist to send to Claude.
- Loads the linked resume's `parsed_text` (not the embedding — rerank works on text).
- Calls `AiClient::forUser($actingUser)->rerank($resumeText, $shortlistJobs)`.
- For each returned entry, writes `stage2_score`, `stage2_rationale`, `stage2_gaps`, `stage2_narrative`, and `stage2_at = now()` on the corresponding `job_matches` row.
- Failure modes: `CostCeilingExceeded` → release with the same midnight-floor delay as `EmbedResume`. `ProviderRateLimited` → release with retry-after. `RerankFailed` → log, update `job_matches.stage2_rationale` to a sentinel "rerank unavailable" value (NOT null, so the feed's "needs rerank" query doesn't keep picking the same row), and return. This prevents runaway retries on a specific prompt that Claude consistently can't parse.
- Job can be dispatched synchronously (via `dispatchSync` from the feed controller) or asynchronously (nightly). Budget: a synchronous batch of 20 jobs should complete in 2-3s against Claude Sonnet.

### 2.3 `NightlyRerankRefresh` scheduled job

- Runs daily at 04:00 UTC (configurable via `site_settings.nightly_rerank_hour_utc`).
- Iterates active `search_profiles` with a linked resume (chunkById 100).
- For each, dispatches `RerankShortlist($profile->id, 50, null)` — system-attributed, batch size 50.
- The 50-batch splits into `ceil(50 / rerank_max_batch_size)` subcalls inside `AiClient::rerank()`; default `rerank_max_batch_size = 20`.

### 2.4 Feed controller integration

- `FeedController::show()` is extended: after loading the paginated matches, it checks whether the first page contains any rows with `stage2_at` older than `stage2_ttl_hours` (or null). If yes AND rerank is enabled in site settings, it dispatches `RerankShortlist` SYNCHRONOUSLY (`dispatchSync`) with `shortlistSize` = size of the stale subset (bounded by `site_settings.rerank_shortlist_size`, default 20).
- The synchronous call is wrapped in a try/catch with a 4-second timeout budget. On any exception, the feed renders with stage-1 data + a "rationale unavailable" placeholder (the Plan 2 placeholder is repurposed for this degraded state).
- After the sync call completes, the controller reloads the matches from the DB and returns the fresh data.
- A URL param `?raw=1` bypasses the rerank dispatch and returns stage-1 data — useful for admin debugging and for users who want the fast path.

### 2.5 `JobMatchCard` + `RationaleSlot` updates

- `RationaleSlot.vue` stops rendering the "AI rationale pending" placeholder and instead accepts a `match.stage2_rationale`, `match.stage2_gaps`, and `match.stage2_narrative`.
- When all three are null, it falls back to the Plan 2 placeholder ("Career-arc rationale pending — refresh after a moment") and triggers the feed's polling pattern (same one Task 5 introduced for resume upload).
- Gaps render as chips with color based on severity: blocker = oxblood, stretch = ink-3, minor = hairline-outlined.
- Fit score renders as a small rounded badge in the card header next to the title.
- Narrative renders as the card subtitle, *replacing* the company-name-only subtitle Plan 2 used.

### 2.6 New drivers

- `WorkableDriver` — Workable's `/v3/accounts/{company}/jobs` endpoint. Same ATS shape as Lever.
- `AshbyDriver` — Ashby's `/posting/{company}` endpoint. Same ATS shape.
- `AdzunaDriver` — Adzuna's `/jobs/{country}/search/{page}` endpoint. Paginated. Country defaults to `us`; configurable per `job_sources` row via a `config_json` jsonb column (see §3.2).

### 2.7 Module boundaries (unchanged but reaffirmed)

- Rerank knows nothing about drivers. Drivers know nothing about rerank.
- `AiClient::rerank()` takes plain text + job data arrays — it does not query the DB itself.
- `RerankShortlist` is the only code that joins rerank output onto `job_matches` rows.
- The feed controller's synchronous-rerank branch is the only caller of `dispatchSync(RerankShortlist::class)`.

---

## 3. Data model additions

### 3.1 Migrations

No new tables. Three adjustments:

1. **`job_sources.config_json` jsonb** (new nullable column) — per-source config that doesn't fit in existing columns. Adzuna uses `{"country": "us"}`. Workable + Ashby + Greenhouse + Lever + RemoteOK ignore it. Added as a forward-compatible extension point so future drivers don't need schema changes.
2. **`site_settings` new keys** (seeded by amended `SiteSettingsSeeder`):
   - `rerank_enabled` (bool, default `true`)
   - `rerank_shortlist_size` (int, default `20`)
   - `rerank_max_batch_size` (int, default `20`)
   - `stage2_ttl_hours` (int, default `24`)
   - `nightly_rerank_hour_utc` (int, default `4`)
   - `anthropic_rerank_model` (string, default `claude-sonnet-4-6`)
3. **`ai_usage_events.context_id` semantics expanded** — already jsonb-free string column on the schema. Rerank writes the search profile id; the existing embed writes still pass null. No migration needed; documented in the model + AiClient.

### 3.2 Seed changes

`JobSourcesSeeder` appends three more rows:

- `('workable', 'shopify', 'Shopify (Workable)')` — real public board.
- `('ashby', 'vercel', 'Vercel (Ashby)')` — real public board.
- `('adzuna', 'us', 'Adzuna US')` — country-scoped; `config_json = {"country": "us"}`.

Plan 2's 9 seeded sources remain intact.

---

## 4. Data flow

### 4.1 Flow C′ — Feed view with stage-2 (replaces Plan 2 §4.3)

1. User opens `/feed/{profile}`. Controller loads page-1 matches (20 per page) ordered by *fit score DESC, then stage-1 score DESC* (null fit_score sorts last). `stage2_at` is a nullable timestamp.
2. Controller computes `$stale` = matches on page 1 where `stage2_at` is null OR older than `stage2_ttl_hours`. If non-empty AND `rerank_enabled`, it calls `RerankShortlist::dispatchSync($profile->id, count($stale), $user->id)`.
3. The sync dispatch returns within ~3s. The controller reloads page-1 matches and returns the page to the client.
4. If the sync dispatch throws (cost ceiling, rate limit, Claude error), the page renders with the stale stage-1 data and `<RationaleSlot>` in its fallback state. An Inertia flash message ("AI rationale temporarily unavailable") surfaces the degradation.
5. Page 2+ requests never trigger sync rerank — they read whatever's in the DB. Users who scroll past the first page see stage-2 for rows the nightly refresh processed, and fallback state for the rest.

### 4.2 Flow D — Nightly rerank refresh

1. At `nightly_rerank_hour_utc` (default 04:00 UTC), the scheduler dispatches `NightlyRerankRefresh`.
2. The job iterates active `search_profiles` with a linked resume (chunkById 100).
3. For each, it dispatches `RerankShortlist($profile->id, 50, null)` to the queue (async, not sync).
4. Horizon workers pick up the queued `RerankShortlist` jobs. Each processes the profile's top-50 stale matches (`stage2_at IS NULL OR stage2_at < now() - stage2_ttl_hours`).
5. Cost: per-profile nightly cost ≈ 50 jobs / `rerank_max_batch_size` × Claude Sonnet rerank cost. At the default 20-batch size, that's 3 Claude calls per profile per night. For 1,000 active profiles: 3,000 Claude calls nightly ≈ ~$30-60 depending on token counts. Bounded, predictable.

### 4.3 Flow A′ and Flow B (ingestion paths) — unchanged

Plan 2's parse/embed/fan-out pipeline and driver fetch pipeline continue to work as-is. Only the drivers list grows.

### 4.4 Failure modes

- `AiClient::rerank()` returns unparseable or incomplete JSON → `RerankFailed` → `RerankShortlist` writes the sentinel "rerank unavailable" state to the match row and logs. Does not retry.
- `AiClient::rerank()` hits cost ceiling (user-attributed call) → `CostCeilingExceeded` → `RerankShortlist::release(secondsUntilMidnight, min 60)`.
- `AiClient::rerank()` hits rate limit → release with retry-after.
- `NightlyRerankRefresh` encounters an error dispatching for a single profile → log, continue. Per-profile failure-isolated.
- Driver fetch for Workable/Ashby/Adzuna fails → same `DriverFetchFailed` + auto-pause pattern from Plan 2.

---

## 5. Key interfaces

### 5.1 `AiClient::rerank()`

```php
class AiClient
{
    public function rerank(string $resumeText, array $jobs): array;
}
```

- **Input**: `$jobs` is a list of 1-N entries, each `['id' => int, 'title' => string, 'company' => string, 'description' => string]`. Description should be trimmed to ~2000 chars per job to keep prompt size bounded. `RerankShortlist` handles the trim.
- **Output**: ordered list matching input indices, each `['job_id' => int, 'fit_score' => int (0-100), 'rationale' => string, 'gaps' => array<{'requirement': string, 'severity': 'blocker'|'stretch'|'minor'}>, 'narrative' => string]`.
- **Exceptions**: `RerankFailed` (structured-output validation failed), `CostCeilingExceeded`, `ProviderRateLimited`.
- **Contract rule**: `AiClient::rerank()` MUST NOT partially-succeed. Either every input job gets a response entry or the method throws. This keeps `RerankShortlist`'s write loop straightforward.

### 5.2 `JobSourceDriver` contract — unchanged

Plan 2's contract is the contract. Three new implementations land without changing the interface.

### 5.3 `CanonicalJobData` DTO — gains no new fields

Plan 2's DTO shape is sufficient for Workable, Ashby, and Adzuna.

---

## 6. Error handling & testing strategy

### 6.1 Error handling deltas from Plan 2

- New typed exception: `App\Services\Ai\Exceptions\RerankFailed`. Carries the raw Claude response for debugging.
- `RerankShortlist` treats `RerankFailed` as a terminal (don't-retry) failure for the specific match set. Cost/rate exceptions are transient (release and retry).
- Feed controller's sync-rerank dispatch wraps all exceptions in a catch that degrades to stage-1 rendering. Never propagates a 500 to the user over a Claude error.

### 6.2 New tests

**Feature tests (real DB, `Http::fake()` for Claude):**

- `AiClientRerankTest` — happy path (valid structured output); `RerankFailed` on unparseable JSON; `RerankFailed` when a single input job is missing from Claude's response; cost-ceiling and rate-limit translations.
- `RerankShortlistTest` — writes stage-2 columns on all returned matches; handles partial shortlist (fewer stale rows than `$shortlistSize`); handles zero-stale case (no-op, no Claude call); sentinel write on `RerankFailed`; release-with-delay on `CostCeilingExceeded`.
- `NightlyRerankRefreshTest` — dispatches per active profile; skips profiles with no resume; chunkById boundary behavior.
- `FeedControllerRerankTest` — sync rerank on feed view when top-K is stale; fallback to stage-1 on Claude error; `?raw=1` bypasses rerank; fit score drives default order.
- `RationaleSlotTest` (Vue component test via a new `tests/Unit/Vue/RationaleSlotTest.ts` with `@vue/test-utils`) — renders stage-2 content when present, placeholder when null, correct severity chip colors.

**Driver unit tests (one per new driver):**
- `WorkableDriverTest` (4 tests, fixture-driven)
- `AshbyDriverTest` (4 tests, fixture-driven)
- `AdzunaDriverTest` (5 tests — extra test for pagination behavior)

**E2E:**
- Update the existing Playwright spec to fix the Plan 2 selector mismatch.
- Extend it to assert the card now shows a fit score badge, narrative subtitle, and at least one gap chip.

**Upstream drift:**
- Activate `JobSourcesUpstreamDriftTest` against Stripe's Greenhouse board; env-var gated; documented as nightly-CI-only in README.

### 6.3 Budget guardrails in tests

- `AiClientRerankTest` uses a mocked Claude response fixture (hand-written JSON). No real API calls in CI.
- `FeedControllerRerankTest` binds a fake `AiClient` via the existing Plan 2 `TestingServiceProvider` pattern, extended with `rerank()` returning deterministic scoring.

---

## 7. Preconditions, setup, and docs updates

### 7.1 No new dev-env requirements

Plan 2's Docker Postgres + pgvector + Redis stack is sufficient. No new composer packages, no new npm packages, no new system binaries.

### 7.2 CLAUDE.md updates

Three new architecture bullets appended:
- Stage-2 rerank is orchestrated by `RerankShortlist` (sync for feed views, async for nightly).
- `NightlyRerankRefresh` runs daily at the site_settings-configured UTC hour; reranks top-50 per active profile.
- `JobMatchCard`'s `RationaleSlot` renders real stage-2 output when present and falls back to a "rerank pending" state otherwise.

Driver additions noted in the "drivers" section (unchanged structure).

Dev-commands section gains `php artisan throughline:rerank-now {profileId}` — a small admin-scoped artisan command Plan 3 adds for manually triggering a rerank without going through the feed view. Useful for dogfood debugging.

### 7.3 README.md updates

Under "What's here now": update the bullet list to reflect Plan 3's additions (three more drivers, stage-2 rerank, nightly refresh). Small edit; no shape change.

---

## 8. Deferred to Plan 4 and beyond

- **Plan 4**: application pipeline (`applications`, `application_events`, `application_contacts`, `application_documents`), kanban UI, reminders, feed "save" action, Remotive + USAJobs drivers.
- **Plan 5**: full admin dashboard — overview cards, user impersonation, analytics with cost charts, settings editor, Horizon mount, error log, audit log viewer. The Plan 3 `rerank_enabled` setting is readable by the new feed controller path today; Plan 5 will expose it in the admin UI.
- **Plan 6**: polish — Reverb real-time push (replace polling on resume detail + feed), pgvector ivfflat retuning once data volume justifies, brand-string leak test, settings-driven theming.

---

## 9. Definition of done for Plan 3

- `composer run dev` + `docker compose up -d` + existing `.env` gets you a working app where:
  - A user with a parsed resume, an active search profile, and seeded jobs sees a feed where every top-20 card renders fit score + narrative + rationale + gap chips.
  - Opening a feed that has stale top-K triggers a ~3s sync rerank; refreshing the page shows fresh stage-2 data.
  - `?raw=1` bypasses rerank and shows stage-1 order.
  - Stopping the queue worker, logging in, and hitting the feed produces the fallback "rationale unavailable" state without a 500.
- All new feature and driver tests green. Full suite should land around 125-135 tests.
- `php artisan throughline:rerank-now {profileId}` populates stage-2 columns on the profile's top matches immediately.
- CLAUDE.md and README reflect the new architecture.
- The Playwright happy-path spec passes end-to-end and asserts stage-2 rendering.
