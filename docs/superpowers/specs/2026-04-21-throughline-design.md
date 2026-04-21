# Throughline — Design Spec

- **Status:** Draft pending user review
- **Author:** David Miller (with Claude Code)
- **Date:** 2026-04-21
- **Product name:** Throughline
- **Repository:** standalone (extracted from `digitalseraph-meta` monorepo after Plan 1)
- **Initial deployment host:** `jobber.improbica.com` (non-authoritative — see §10 Deployment Portability)

> **Layout note:** This spec was written while the project lived inside the `digitalseraph-meta` monorepo at `web/throughline-web/`. After Plan 1 shipped, the project was extracted to its own repository. Any `web/throughline-web/...` path references below are now repo-root-relative (drop the prefix).

## 1. Purpose and Scope

Throughline is a multi-user web application that helps job seekers find and win roles that fit the career they're already building. Rather than acting as a job aggregator or a tracking spreadsheet, Throughline reads your resume as a narrative, surfaces a small daily set of high-fit roles, and shows — transparently — exactly why each role matches and where the gaps are.

### Product stance

Three commitments define Throughline and shape every downstream decision:

1. **Radical match transparency.** Every job a user sees arrives with a plain-language rationale tying specific resume evidence to specific role requirements, plus an honest enumeration of gaps. We never show "87% match" without saying *what* the 87 is measuring.
2. **Depth over breadth.** We deliberately surface a small daily set of strongly-matched roles per search profile instead of an infinite feed. Product surfaces reward going deep on a few jobs (context, rationale, company notes, interview prep attachments) rather than mass-applying to many.
3. **Career-narrative lens.** The resume is treated as a story arc. Matches are framed not only in skill terms but in narrative terms — "this continues your design-engineering bridge," "this is an intentional pivot into X." Search profiles and resume variants pair naturally with this framing.

Secondary consequence: the visual and verbal brand (see §9) is editorial and precise, not frantic. This is a *craft tool for thoughtful applicants*, not a job-spam firehose.

### First vertical slice (this spec)

- **In scope:** user auth (email/password + Google + GitHub OAuth), multi-variant resume management, per-user search profiles, job ingestion from ATS/aggregator APIs, two-stage matching (embeddings shortlist + LLM rerank with per-job prose rationale and gap enumeration), full-pipeline application tracking (stages, reminders, contacts, notes, document attachments), admin dashboard (site settings, source management, analytics, usage, audit), Throughline brand identity and theming system.
- **Out of scope (v1):** auto-apply / form fill, resume generation or per-job tailoring, personal pipeline analytics / "honest funnel" coaching, LinkedIn / Indeed / Glassdoor scraping, billing / subscriptions, email digest notifications (in-app reminders only), multi-tenant admin.

### Non-goals

- Not a clone of Huntr or Teal. Discovery and rationale are first-class; tracking supports that, not the other way around.
- Not attempting to scrape sites whose terms of service prohibit it. Coverage grows by adding API sources, not by circumventing anti-bot systems.
- Not a breadth-maximizing aggregator. The product actively discourages spray-and-pray.

## 2. Architecture

### Tech stack

- **Backend:** Laravel 13 (PHP 8.3+), Inertia, Laravel Fortify (password auth), Laravel Socialite (Google + GitHub OAuth from day one), Laravel Horizon (queue dashboard)
- **Frontend:** Vue 3 + TypeScript + Tailwind CSS, Inertia adapter (matches `improbica-web` conventions)
- **Database:** PostgreSQL 16 with the `pgvector` extension for embedding storage
- **Cache / queues:** Redis
- **Object storage:** S3-compatible in prod; local disk in dev (Laravel filesystem)
- **AI providers:** OpenAI `text-embedding-3-small` for embeddings; Anthropic Claude for resume parsing and job re-ranking with rationale
- **Monorepo placement:** self-contained at `web/throughline-web/`, with its own `composer.json`, `package.json`, `CLAUDE.md`, `README.md`, identical shape to `web/improbica-web/`

### Modules

- **Auth & user profile** — Fortify + Socialite (Google, GitHub). OAuth identities linked via `social_accounts` so one user can attach multiple providers. User roles: `user`, `admin`. First user on fresh install auto-promoted to `admin`.
- **Resume module** — PDF/DOCX upload; parsing via Claude to structured JSON + plain text; embedding via OpenAI. Multiple named variants per user ("Staff frontend," "Eng manager," etc.).
- **Search-profile module** — per-user criteria (role keywords, locations, remote mode, comp floor, industries, seniority, excluded companies). May pair 1:1 with a resume variant but is not required to.
- **Ingestion workers** — one queued job class per *source type* implementing a common `JobSourceDriver` contract. Starter drivers: Greenhouse, Lever, Workable, Ashby, Adzuna, RemoteOK, Remotive, USAJobs. Scheduler fires them per-source on a configurable cadence.
- **Normalization layer** — converts each driver's raw payload into a canonical `jobs` row.
- **Matching engine** — stage 1: cosine similarity over embeddings, bounded by the profile's hard filters. Stage 2: Claude re-ranks the top-K shortlist and returns per-job fit score, prose rationale, and an explicit gaps list. Per-user cost ceilings enforced by the `AiClient` wrapper.
- **Application tracker** — full pipeline with states, events timeline, contacts, documents, reminders.
- **Admin module** — site settings, user management (inc. impersonation), job source management, job browser, analytics, error log, audit log, Horizon (queue) dashboard.
- **Brand / theming system** — central source of truth for name, palette, typography, wordmark asset, email sender identity; consumed by layouts, mail, admin overview. See §9 and §10.
- **Frontend** — Inertia pages for: feed (per search profile), job detail (with rationale + gaps panel, narrative framing, save CTA), resume manager, search profile editor, application pipeline (kanban), admin pages.

### Key boundaries

| Unit | Responsibility | Interface |
|---|---|---|
| `JobSourceDriver` | Fetches from one source, yields canonical `JobDto` records | `fetch(JobSourceConfig): iterable<JobDto>` |
| `AiClient` | Wraps OpenAI + Claude calls, rate-limits, records usage | `embed(string): vector`, `rerank(Resume, Job[]): ScoredRationale[]`, `parseResume(file): StructuredResume` |
| `SiteSettings` | In-memory, Redis-cached view of admin-tunable settings | `SiteSettings::get(key)`, `SiteSettings::set(key, value)` |
| `BrandConfig` | Resolves active brand — name, palette, wordmark asset, copy tokens — from env + `site_settings` | `BrandConfig::current(): Brand` |
| `MatchingService` | Executes stage 1 + orchestrates stage 2 (rationale + gaps) | `matchesFor(SearchProfile): Paginator<JobMatch>` |
| `ApplicationService` | Transitions state, appends events, schedules reminders | `transition(Application, newStatus, payload)` |

Each unit is individually testable — drivers with recorded fixtures, `AiClient` with HTTP fakes, `MatchingService` with deterministic fake embeddings.

## 3. Data Model

### Users & auth

- **`users`** — id, email, name, password (nullable for OAuth-only), email_verified_at, role (`user`|`admin`), last_login_at, timestamps
- **`social_accounts`** — id, user_id, provider (`google`|`github`), provider_user_id, access_token (encrypted), refresh_token (encrypted), timestamps. Unique on (provider, provider_user_id).

### Resumes

- **`resumes`** — id, user_id, name, source_file_path, source_mime, parsed_text (longtext), structured_json (jsonb — extracted skills, titles, companies, years), embedding (vector(1536)), is_default, parse_status (`pending`|`parsed`|`parse_failed`), timestamps
- Re-parse + re-embed on file replace.
- Fallback: if parsing fails after retries, user can paste plain text; `structured_json` stays null and matching uses `parsed_text` alone.

### Search profiles

- **`search_profiles`** — id, user_id, resume_id (nullable), name, role_keywords (jsonb array), locations (jsonb array), remote_mode (`any`|`remote_only`|`hybrid_ok`|`onsite`), comp_min, comp_currency, industries (jsonb), seniority_levels (jsonb), excluded_companies (jsonb), is_active, timestamps

### Jobs (canonical, global)

- **`jobs`** — id, source (enum of source slugs), source_id, source_board (company slug for ATS), company_name, title, description (longtext), location_raw, locations (jsonb, normalized), remote_mode, comp_min, comp_max, comp_currency, seniority, employment_type, apply_url, posted_at, last_seen_at, closed_at (nullable), embedding (vector(1536)), raw_payload (jsonb), timestamps. Unique on (source, source_id).
- **`job_sources`** — id, source, identifier (e.g. Greenhouse board slug), display_name, is_enabled, last_fetched_at, last_error, fetch_cadence_minutes, timestamps. Seeded with a curated starter set; admin-editable.

### Matching

- **`job_matches`** — id, user_id, search_profile_id, job_id, stage1_score (float), stage2_score (int 0–100, nullable), stage2_rationale (text, nullable), stage2_gaps (jsonb, nullable — structured list of missing / weak requirements with severity), stage2_narrative (text, nullable — one-sentence "how this fits your arc" framing), stage2_at (timestamp, nullable), dismissed_at (nullable), created_at. Unique on (search_profile_id, job_id).
- Stage 2 only populated for top-K shortlist on demand or on nightly refresh.

### Application tracking

- **`applications`** — id, user_id, job_id, resume_id, status (`saved`|`applied`|`phone_screen`|`technical`|`onsite`|`offer`|`rejected`|`withdrawn`), applied_at, next_action_at, notes (text), timestamps
- **`application_events`** — id, application_id, type (`status_change`|`note`|`interview`|`reminder`|`contact_added`|`document_attached`), payload (jsonb), occurred_at, timestamps. Append-only.
- **`application_contacts`** — id, application_id, name, role (`recruiter`|`hiring_manager`|`interviewer`|`referral`|`other`), email, phone, linkedin_url, notes, timestamps
- **`application_documents`** — id, application_id, kind (`cover_letter`|`resume_snapshot`|`portfolio`|`other`), file_path, file_name, mime, uploaded_at

### Admin & observability

- **`site_settings`** — id, key (unique), value (jsonb), type (`string`|`int`|`bool`|`json`), description, updated_by, updated_at. Initial keys: `site_name` (default `"Throughline"`), `site_tagline`, `public_url` (display only — canonical URL lives in `APP_URL`), `default_source_cadence_minutes`, `matching_top_k`, `rerank_shortlist_size`, `rerank_enabled`, `cost_ceiling_per_user_per_day_usd`, `signups_enabled`, `maintenance_mode`, `brand_asset_overrides` (json — optional per-install overrides).
- **`admin_activity_log`** — id, admin_user_id, action, target_type, target_id, payload (jsonb), ip, created_at. Audit trail for every mutating admin action (including impersonation start/stop).
- **`ai_usage_events`** — id, user_id (nullable — for system calls), provider (`openai`|`anthropic`), model, operation (`embed`|`rerank`|`parse`), input_tokens, output_tokens, estimated_cost_usd, context_type (`job`|`resume`|`rerank_batch`), context_id, created_at. Written by `AiClient` after every call. Powers cost charts and per-user ceilings.

### Non-obvious choices

1. **Jobs are global, not per-user.** One canonical record per `(source, source_id)`; matching fans out to users through `job_matches`.
2. **`job_matches` is explicit, not computed on the fly.** Feed reads are indexed lookups; stage-2 state is persistent so Claude tokens aren't spent twice on the same `(resume, job)` pair.
3. **`stage2_gaps` and `stage2_narrative` are first-class columns**, not just strings baked into the rationale. Gaps render as a structured list; narrative drives the card subtitle and job-detail hero. This is the data shape the transparency + narrative stance demands.
4. **`application_events` is append-only.** Free audit trail and trivial timeline UI.
5. **OAuth tokens are encrypted at rest** via Laravel's `encrypted:` cast.
6. **Site settings sit in a table, not env.** Admins tune runtime knobs without redeploy; secrets still live in env. Brand asset overrides are allowed but have safe defaults — a fresh install ships as "Throughline."

## 4. Data Flow

### Flow A — Ingestion (per source, scheduled)

1. Scheduler fires every N minutes per enabled `job_sources` row → dispatch `FetchJobSource`.
2. `FetchJobSource` calls the driver, paginates the source, upserts into `jobs` on `(source, source_id)`. Existing records get `last_seen_at` refreshed. Records unseen for 30 days get `closed_at` set.
3. For each new/changed job → dispatch `EmbedJob` → OpenAI → store vector on `jobs.embedding`.
4. After embedding lands → dispatch `FanOutStage1Matches` → for each active `search_profile`, compute cosine similarity and insert a `job_matches` row if the job passes the profile's hard filters.
5. Driver errors update `job_sources.last_error` and surface in admin UI; queue retries with exponential backoff (3 attempts). After 3 consecutive scheduler-level failures, source is auto-paused.

### Flow B — Resume upload / update

1. User uploads file → stored; `resumes` row created with `parse_status=pending`.
2. Dispatch `ParseResume` → Claude call → `parsed_text` + `structured_json` populated; `parse_status=parsed`.
3. Dispatch `EmbedResume` → OpenAI embedding stored on `resumes.embedding`.
4. Dispatch `RecomputeStage1ForResume` → for every `search_profile` using this resume, recompute stage-1 scores on existing `job_matches` rows (or regenerate them).
5. UI subscribes via Laravel Reverb (or polls) for a "resume ready" notification.

### Flow C — Viewing the feed

1. User opens feed for a search profile → query top-K `job_matches` ordered by `stage1_score` DESC where not dismissed.
2. If the top page lacks fresh stage-2 scores → `RerankShortlist` is invoked synchronously (bounded, typically ≤ 3s): batches ~20 jobs into one Claude call with the resume text, receives structured JSON `[{job_id, fit_score, rationale, gaps[], narrative}]`, writes all four into `job_matches`. If Claude fails, feed degrades gracefully to stage-1 order with a per-card "AI rationale unavailable" indicator.
3. Subsequent pages hit cached stage-2 data.
4. A nightly `NightlyRerankRefresh` job re-ranks the top ~50 for each active profile so the morning feed feels fresh.

### Application lifecycle (sub-flow)

- "Save" from feed → `applications` row created with `status=saved`; `job_id` + `resume_id` captured.
- Every status change appends an `application_events` row; most transitions allow setting `next_action_at` for reminders.
- Daily `ApplicationReminderScan` dispatches in-app notifications for applications where `next_action_at <= now()` and status is non-terminal.

### Cost & rate considerations

- Embedding cost scales only with *new jobs and new/updated resumes*.
- LLM re-ranking is bounded to top-K per profile per refresh — never every job, never every view.
- Per-user daily cost ceiling check in the `AiClient`: over limit → skip stage-2 for today and show stage-1 results with notice.
- Redis-backed token bucket rate-limiter shared across the app for OpenAI and Claude clients.

## 5. Admin Dashboard

### Access control

- `users.role` enum (`user`|`admin`). Default `user`. First user on fresh install auto-promoted to `admin`.
- Middleware `EnsureAdmin` gates `/admin/*` routes.
- Every mutating admin action is logged to `admin_activity_log`.

### Pages

1. **Overview** — cards for total users, 7-day-active users, total jobs, jobs ingested today, total matches, applications by stage, AI spend today/this month, source-health summary.
2. **Users** — list with search (email/name), linked auth providers, role, last_login_at, application count. Detail view: edit role, deactivate, impersonate (persistent banner + audit log entries on start/stop).
3. **Job sources** — CRUD over `job_sources`: add a Greenhouse/Lever/Ashby/etc. identifier, set cadence, enable/disable, "fetch now" button, live `last_fetched_at` + `last_error`.
4. **Jobs browser** — read-only paginated table; filters by source, company, posted date, has-embedding, closed status.
5. **Analytics** — time-series charts: daily ingestion by source, daily matches generated, rerank calls, AI cost trend, application funnel (saved → applied → interview → offer).
6. **Settings** — key/value editor over `site_settings`. Secrets from `.env` shown read-only and masked. Brand asset overrides editable (see §10).
7. **Queues** — Laravel Horizon mounted at `/admin/horizon` behind `EnsureAdmin`.
8. **Error log** — failed resume parses, failed embeddings, failed reranks, with retry button per row.
9. **Audit log** — paginated view of `admin_activity_log`.

### Settings hydration

`SiteSettings` is a singleton service: loads all rows on boot, caches in Redis, invalidates on any settings write. Code reads settings via `SiteSettings::get('key')` — no DB hit on the hot path.

## 6. Error Handling

- **Ingestion failures** — per-driver try/catch, write to `job_sources.last_error`, exponential-backoff queue retry (3 attempts), auto-pause source after 3 consecutive schedule-level failures. Source health dashboard surfaces status.
- **LLM / embedding failures** — `AiClient` wraps calls, honors rate-limit headers, retries with jitter. On persistent failure, embeddings are retried on the next embed cycle; re-ranking falls back to stage-1 order with a visible indicator.
- **Parsing failures** — `parse_status=parse_failed` surfaces a UI error with retry + "paste text manually" fallback. `structured_json` is nullable to permit this degraded state.
- **Rate limiting** — Redis-backed token bucket shared by OpenAI + Claude clients. Per-user daily cost ceiling soft-enforced (over → skip stage-2 with notice).
- **OAuth failures** — Socialite exceptions render a friendly "sign-in failed" page with retry link. No partial user rows on failure.

## 7. Testing Strategy

- **Feature tests (Pest/PHPUnit, `RefreshDatabase`)** — HTTP-level coverage of every user-facing flow: auth (password + Socialite stubs for Google + GitHub), resume upload → parsed state (Claude mocked), search-profile CRUD, feed render (including rationale + gaps + narrative rendering), application state transitions, admin pages.
- **Driver unit tests** — one test class per `JobSourceDriver`. Each uses a recorded fixture (JSON saved from the real API) and verifies the driver produces the correct canonical `jobs` upsert. HTTP faked via `Http::fake()`; real network calls never made in CI.
- **Matching tests** — deterministic seeded fake embeddings verify cosine-similarity ranking and hard-filter exclusions. LLM re-rank tested against mocked Claude responses, including schema validation for the rationale/gaps/narrative structured output.
- **Brand / theming tests** — a small set confirming that brand name, tagline, wordmark asset, and mail sender resolve from config/env rather than being hardcoded anywhere in templates or mail.
- **E2E smoke (Playwright)** — single happy-path test: sign in → upload resume → create search profile → see ranked feed with rationale + gaps → save a job → move it through pipeline states. Runs against seeded DB with fixture jobs + fake embeddings; no external APIs.
- **Upstream drift test (opt-in, nightly)** — one test hits a known-stable Greenhouse board. Gated by env var so it never runs on PR CI.
- **No mocked databases** — feature tests hit the real DB via `RefreshDatabase`.

## 8. Out of Scope for v1

To keep the first implementation plan focused, the following are explicitly deferred:

- Auto-apply / form filling
- Resume *generation* or per-job tailoring
- Personal pipeline analytics ("honest funnel" self-coaching)
- LinkedIn / Indeed / Glassdoor scraping
- Billing / subscriptions
- Email digest notifications (in-app reminders only for v1)
- Multi-tenant admin (single admin role is enough)

Each of the above is a candidate for its own future spec in `docs/superpowers/specs/`.

## 9. Brand Identity

The brand is a first-class deliverable of v1, not a post-launch polish pass. It must ship with the product and be coherent across web, email, admin, and favicon.

### Name

**Throughline.** A throughline is the thread that runs through a story — and through a career. Product language flows naturally: *on-throughline jobs*, *off-throughline detours*, *your throughline*.

### Wordmark

The logotype is **"Throughline"** set in **Inter 800** (tight tracking, letter-spacing `-0.045em`) with an **oxblood waypoint thread** drawn directly beneath it — a horizontal rule carrying a small filled circle at each end and three intermediate waypoints, evoking stations on a line or milestones in a career.

The waypoint thread is the central motif and must be usable standalone as:

- Section dividers in product UI
- Stage markers in the application pipeline kanban
- Milestone markers on resume timelines
- Loading/progress indicators
- An iconic favicon (simplified waypoint thread, no word)

### Design system

A *hybrid* of editorial, workshop, and instrument sensibilities — chosen to match the product stance.

| Role | Choice |
|---|---|
| Display serif (headlines, narrative) | **Fraunces** (variable; italic at high optical size for emphasis) |
| Body sans (UI, forms) | **Inter** |
| Mono (labels, scores, metadata) | **IBM Plex Mono** |
| Editorial serif (rationale / prose blocks) | **IBM Plex Serif** italic |

**Palette**

| Token | Value | Use |
|---|---|---|
| `--bone` | `#fdfaf5` | Primary background |
| `--bone-2` | `#f5ede0` | Card / elevated surface |
| `--ink` | `#1a1410` | Primary text |
| `--ink-2` | `#3a2e26` | Secondary text, body |
| `--ink-3` | `#6b5d52` | Tertiary text, labels |
| `--oxblood` | `#a83a1a` | Single saturated accent — thread, selection, CTAs |
| `--hairline` | `rgba(26, 20, 16, 0.12)` | Borders, dashed rules |

Strict single-accent discipline: oxblood is the only saturated color. No gradients. No secondary accent hues. Semantic colors (success / warning / error) are opt-in and desaturated when they must appear.

### Voice

Editorial, precise, quietly confident. Writes in complete sentences. Names the tradeoff honestly. Never uses "unlock," "crush," "game-changer," or exclamation points. Uses italic serif for emphasis, not uppercase. Rationale prose is first-person-plural observational, not marketing ("This role continues the design-engineering bridge…" not "PERFECT MATCH!!!").

### Assets ship list (v1)

- SVG wordmark (waypoint thread + "Throughline")
- SVG standalone waypoint mark (favicon + small-scale use)
- Favicons at 16/32/48/180/512 + `manifest.webmanifest`
- OG / social image template
- Mail header template with wordmark
- Tailwind preset exposing the tokens above

## 10. Deployment Portability

The application must never bake its public URL or brand-specific strings into source. The initial deployment is `jobber.improbica.com` but may later move to `jobber.digitalseraph.com` or a different host entirely, and nothing in the product should need a code change when that happens.

### Rules

- **Public URL** — resolved from `APP_URL` only. All absolute links, OAuth redirect URIs, mail links, and social embeds derive from it.
- **Site name & tagline** — come from the `site_settings` table (`site_name`, `site_tagline`). The table defaults are `"Throughline"` and the v1 tagline; an admin can override without a redeploy.
- **Mail sender** — `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME` in env, with `site_name` as fallback display.
- **Brand assets** — resolved through `BrandConfig`. Default assets ship in the app; `site_settings.brand_asset_overrides` allows per-install replacement (wordmark, favicon, OG image paths) without rebuilding.
- **OAuth redirect URIs** — constructed relative to `APP_URL` at runtime. Provider consoles must be configured with the current host(s); our code never hardcodes host strings.
- **No host strings in code** — enforced by a brand / theming test that greps templates and mail for literal `improbica.com`, `digitalseraph.com`, or equivalent strings and fails if found.

### Migration story (for a future host change)

When the host eventually changes:

1. Update `APP_URL` in the new environment.
2. Register the new host(s) as allowed OAuth redirect URIs in the Google + GitHub consoles.
3. Update DNS; deploy unchanged code.
4. (Optional) If the brand name itself changes too — update `site_settings.site_name` via the admin UI. No redeploy.

## 11. Open Questions

- Hosting target (Forge / Fly.io / Railway / other) — deferred to the implementation plan since it doesn't affect the design.
- Specific curated Greenhouse/Lever/Ashby starter boards — admin-seedable; the initial seed list can be chosen during setup.
- Whether to use Laravel Reverb (WebSockets) for real-time "resume ready" updates, or short polling — deferred to implementation; polling is the simpler default.
- Whether to ship the Tailwind token package as a local workspace package or simply as a Tailwind preset inside `throughline-web` — deferred; a preset is simpler and adequate for v1 since there's only one consumer.
