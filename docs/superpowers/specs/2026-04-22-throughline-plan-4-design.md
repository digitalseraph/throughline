# Throughline — Plan 4 design (application pipeline + remaining drivers)

**Status:** Design spec.

**Main product spec:** [`2026-04-21-throughline-design.md`](./2026-04-21-throughline-design.md).
**Plan 3 design:** [`2026-04-22-throughline-plan-3-design.md`](./2026-04-22-throughline-plan-3-design.md).

This document scopes **Plan 4 of 6** — the application tracking pipeline that turns the Plan 3 feed's "this is a job I care about" signal into a durable, auditable record of the user's pursuit.

---

## 1. Scope & intent

### What Plan 4 delivers (user-visible)

A signed-in user browsing their feed clicks a "Save" action on any card. That creates an `applications` row in `saved` state, linked to the canonical job + the resume active on the profile at the time. The user navigates to `/applications` and sees a **kanban board** grouping applications by status: `saved`, `applied`, `phone_screen`, `technical`, `onsite`, `offer`, `rejected`, `withdrawn`. They drag a card from `saved` to `applied` — the transition is recorded as an immutable `application_events` row. Each card opens to a **detail view** where they can:

- Add contacts (recruiter, hiring manager, interviewer, referral, other) with name, role, email, phone, LinkedIn URL, notes
- Attach documents (cover letter, resume snapshot, portfolio, other) uploaded to the same disk as resumes
- Set a `next_action_at` reminder; a daily scheduled job surfaces due reminders as an in-app notification badge on the nav
- Read the event timeline (append-only audit trail of everything that's happened)

The application detail view displays the stage-2 rationale + fit score + gaps + narrative that were current on the `job_matches` row at the time of the save, preserved against future rerank overwrites via a snapshot.

### What Plan 4 ALSO delivers (plumbing users don't see)

- **Remotive and USAJobs drivers** — completing the starter set of 8 drivers from the main product spec. Remotive is a single-feed aggregator shape (like RemoteOK); USAJobs is a paginated, authenticated API with the most exotic shape of any driver so far (JSON:API-style response envelope, per-result embedded `MatchedObjectDescriptor`, government-system quirks). Shipping them now completes the spec's ingestion surface.
- **Nav badge** surfacing count of applications with `next_action_at <= now()` and non-terminal status. The badge isn't a full notification system — just a visual cue; a dedicated notifications UI is Plan 6.

### What Plan 4 does NOT deliver

- **Email notifications / digest** — Plan 6 with Reverb.
- **Full admin dashboard** (admin user management, impersonation, analytics, audit log viewer) — Plan 5. Plan 4's event log surfaces in the user's own application detail view; the admin cross-cutting view waits.
- **Application-level comparisons** (e.g., "I'm considering offer A vs offer B") — out of scope for v1.
- **Resume-variant attachment at apply-time** — Plan 4 always captures the resume the user had *linked on the search profile* at save-time. A future plan could let users override per application.

### Sequencing principle

Schema before behavior, behavior before UI, feed integration late. Concretely:

1. All four application tables land in one migration cluster.
2. Models + factories + relation wiring.
3. `Save` from feed — the single user action that creates applications.
4. Application list + kanban UI.
5. Status-transition controller + event-log writes.
6. Detail view with contacts + documents + timeline.
7. Reminder scan + nav badge.
8. Drivers last (they're orthogonal to the application pipeline).

---

## 2. Architecture additions

### 2.1 Four new tables

- **`applications`** — per-user, per-job application record. Status state machine (`saved`|`applied`|`phone_screen`|`technical`|`onsite`|`offer`|`rejected`|`withdrawn`). Carries `applied_at`, `next_action_at`, free-form `notes`, and a `stage2_snapshot` jsonb column holding the rerank output from `job_matches` at save-time. Unique on `(user_id, canonical_job_id)` — a user can't save the same job twice.
- **`application_events`** — append-only audit trail. `type` enum: `status_change`, `note`, `interview`, `reminder`, `contact_added`, `document_attached`. `payload` jsonb carries per-type context (old/new status, free text, meeting time, etc.). Ordered by `occurred_at` for timeline render.
- **`application_contacts`** — per-application people (recruiter/hiring-manager/interviewer/referral/other). Name + role + email + phone + linkedin_url + notes.
- **`application_documents`** — per-application file attachments. Kind enum: `cover_letter`|`resume_snapshot`|`portfolio`|`other`. Stored on the same local/S3 disk as resumes; filename + path + mime + size.

### 2.2 Save-from-feed flow

A POST to `/applications` with `job_match_id` in the body:
1. Resolves the match to `(user_id, canonical_job_id, search_profile_id)`.
2. Finds the profile's linked resume; fails with 422 if no resume linked.
3. Snapshots stage-2 data from the match row into `applications.stage2_snapshot`.
4. Creates the `applications` row with `status = 'saved'` and `resume_id = profile.resume_id`.
5. Writes the first `application_events` row: `type = 'status_change'`, `payload = {from: null, to: 'saved'}`.
6. Returns the application id so the feed can mark the card as saved.

If a `(user_id, canonical_job_id)` application already exists, return 409 with the existing id; the feed renders the card as "already saved."

### 2.3 Application pipeline controllers

- **`App\Http\Controllers\Applications\ApplicationController`** — `index` (kanban or list), `show` (detail), `store` (save-from-feed), `update` (status transitions, notes, next_action_at), `destroy` (withdraw → sets status=withdrawn and records event; not a hard delete).
- **`App\Http\Controllers\Applications\ContactController`** — nested CRUD under `applications/{application}/contacts`.
- **`App\Http\Controllers\Applications\DocumentController`** — `index`, `store` (upload), `destroy` under `applications/{application}/documents/{document}`.

Every mutating action writes one or more `application_events` rows in the same DB transaction as the primary write. The event-log invariant is never violated.

### 2.4 Kanban UI

- `/applications` (index) renders columns for the 8 status values. Each card summarizes: job title, company, fit score badge (from snapshot, not live), applied date, next action date.
- Drag-and-drop uses `vue-draggable-next` (new composer dep); a drop emits a PATCH to `applications/{id}` with `{status: 'newstatus'}`.
- Terminal statuses (`offer`, `rejected`, `withdrawn`) render in a collapsed column that expands on click — reduces visual noise for active job seekers.
- An empty kanban renders the call-to-action: "No saved jobs yet — find one in the feed."

### 2.5 Reminder scan

- **`ApplicationReminderScan`** scheduled job runs daily at `reminder_scan_hour_utc` (new site_setting, default 09:00).
- Queries applications with `next_action_at <= now()`, status in (`saved`, `applied`, `phone_screen`, `technical`, `onsite`, `offer`), and writes an `application_events` row of type `reminder` if one hasn't been written for this `next_action_at` already (dedupe via a `(application_id, type, occurred_at date)` uniqueness check).
- The nav badge count is computed by a simple query at page-render time (no denormalized counter — keep it simple for v1). Cached in Redis for 60s per user to keep nav renders cheap.

### 2.6 Drivers

- **`RemotiveDriver`** — aggregator shape, single feed at `/api/remote-jobs`. Similar to `RemoteOkDriver` but with different JSON shape.
- **`USAJobsDriver`** — paginated + authenticated. Requires `Authorization-Key` header + `User-Agent` identifying the consumer. Country-scoped only to US (no `config_json.country` needed). Shape: `SearchResult.SearchResultItems[]` with `MatchedObjectDescriptor` nested per result.

Both register in `config/throughline.php`, both get seeded rows in `JobSourcesSeeder`, both get 4-5 tests with hand-written fixtures.

### 2.7 Module boundaries

- `ApplicationController` never writes `application_events` directly — a thin `App\Services\Applications\EventWriter` service handles event creation so the append-only invariant is enforced in one place. Controllers call `$eventWriter->logStatusChange($app, $from, $to)` or equivalent.
- `ReminderScan` is the only writer of `reminder`-type events.
- The feed's "Save" action is the only code path that calls `ApplicationController@store` — no admin tool creates applications for a user.

---

## 3. Data model additions

### 3.1 Migration shapes

Five migrations (four tables + one `site_settings` key addition handled via the seeder):

1. **`create_applications_table`**:
   - `id`, `user_id` (FK → users, cascade), `canonical_job_id` (FK → canonical_jobs, cascade), `search_profile_id` (FK → search_profiles, nullOnDelete so deleting a profile doesn't nuke history), `resume_id` (FK → resumes, nullOnDelete).
   - `status` string(24) default `'saved'`.
   - `applied_at` timestamp nullable, `next_action_at` timestamp nullable.
   - `notes` text nullable.
   - `stage2_snapshot` jsonb nullable.
   - `timestamps()`.
   - Unique `(user_id, canonical_job_id)` — one row per (user, job).
   - Indexes: `(user_id, status)` for kanban queries, `(user_id, next_action_at)` for reminder scan.

2. **`create_application_events_table`**:
   - `id`, `application_id` (FK cascade).
   - `type` string(32).
   - `payload` jsonb.
   - `occurred_at` timestamp (NOT `created_at` — explicit semantics for when the event happened vs when the row was written).
   - `timestamps()`.
   - Index `(application_id, occurred_at)` for timeline queries.
   - **No UPDATE permission enforced** at the model level via `Model::preventLazyLoading` + a static boot-method rejection of `::update()`, honoring append-only semantics.

3. **`create_application_contacts_table`**:
   - `id`, `application_id` (FK cascade).
   - `name` string(120), `role` string(24) (enum values: recruiter/hiring_manager/interviewer/referral/other).
   - `email`, `phone`, `linkedin_url` nullable strings.
   - `notes` text nullable.
   - `timestamps()`.
   - Index `(application_id)`.

4. **`create_application_documents_table`**:
   - `id`, `application_id` (FK cascade).
   - `kind` string(24) (enum: cover_letter/resume_snapshot/portfolio/other).
   - `file_path` string, `file_name` string, `mime` string, `size_bytes` integer.
   - `uploaded_at` timestamp default now.
   - Index `(application_id)`.

### 3.2 New `site_settings` keys (via seeder, `firstOrCreate` pattern)

- `reminder_scan_hour_utc` (int, default `9`)
- `nav_reminder_badge_ttl_seconds` (int, default `60`)

### 3.3 Seed expansions

`JobSourcesSeeder` gains two more rows:
- `('remotive', null, 'Remotive')` — single-feed aggregator, identifier null; the partial unique index from Plan 2 Task 7 fix ensures only one such row can exist.
- `('usajobs', null, 'USAJobs (US Federal)')` — same shape, one row per source type when identifier is null.

---

## 4. Data flow

### 4.1 Flow E — Save-from-feed

1. User clicks `Save` on a `JobMatchCard`. Inertia POST to `/applications` with `{job_match_id: N}`.
2. `ApplicationController@store`:
   - Load match, validate ownership.
   - Check `(user_id, canonical_job_id)` unique — if exists, 409.
   - DB transaction: insert applications row, snapshot match's stage-2 columns into `stage2_snapshot`, insert first application_events row (status_change null → saved).
3. Response includes new application id and a `Saved to your applications` flash message. The card on the feed page re-renders with a "Saved" indicator (the Inertia partial reload carries this).

### 4.2 Flow F — Status transition via kanban

1. User drags a card from `applied` to `phone_screen`.
2. Vue emits PATCH `/applications/{id}` with `{status: 'phone_screen'}`.
3. `ApplicationController@update` validates the status is a legal transition (any → any is allowed except from/to `withdrawn` if already terminal — v1 keeps this loose intentionally).
4. DB transaction: update application, insert event row (type=status_change, payload=`{from: 'applied', to: 'phone_screen'}`).
5. Response returns the updated application; Vue confirms the drop.
6. On failure (ownership violation, stale state), Vue reverts the drag visually and surfaces the error.

### 4.3 Flow G — Reminder scan + nav badge

1. `ApplicationReminderScan` runs daily at `reminder_scan_hour_utc`.
2. Queries active applications with `next_action_at <= now()`.
3. For each, writes a `reminder`-type `application_events` row if one hasn't been written for that day already.
4. Nav (Vue) reads `$page.props.reminderBadgeCount` from Inertia shared data (populated by a `HandleInertiaRequests` extension). Redis-cached for 60s per user.
5. Badge click → navigate to `/applications?filter=due`.

### 4.4 Failure modes

- Save-from-feed race (two clicks in quick succession): unique constraint violation → 409, UI shows "Already saved" and navigates to the existing application.
- Status transition on an application that was withdrawn via another session: the update still succeeds, but a DB check can prevent re-opening if desired. v1 permits the reopen and logs an event — the event log preserves intent.
- Reminder-scan duplicate guard: a daily rerun of the scheduler doesn't write a second reminder event for the same `(application_id, next_action_at date)` pair because the scan checks before inserting.
- Driver fetch failures — identical pattern to Plan 2/3 (DriverFetchFailed + auto-pause).

---

## 5. Key interfaces

### 5.1 `ApplicationController` routes

```
GET    /applications                                → ApplicationController@index (kanban or list via ?view=list)
POST   /applications                                → store (save-from-feed)
GET    /applications/{application}                  → show (detail)
PATCH  /applications/{application}                  → update (status, notes, next_action_at)
DELETE /applications/{application}                  → destroy (soft — sets status=withdrawn)
POST   /applications/{application}/contacts         → ContactController@store
PATCH  /applications/{application}/contacts/{id}    → ContactController@update
DELETE /applications/{application}/contacts/{id}    → ContactController@destroy
POST   /applications/{application}/documents        → DocumentController@store
DELETE /applications/{application}/documents/{id}   → DocumentController@destroy
```

All routes gated by `auth` middleware + owner check in each controller (matching Plan 2/3 patterns).

### 5.2 `App\Services\Applications\EventWriter`

```php
class EventWriter {
    public function logStatusChange(Application $app, ?string $from, string $to): ApplicationEvent;
    public function logNote(Application $app, string $body): ApplicationEvent;
    public function logContactAdded(Application $app, ApplicationContact $contact): ApplicationEvent;
    public function logDocumentAttached(Application $app, ApplicationDocument $doc): ApplicationEvent;
    public function logReminder(Application $app): ApplicationEvent;
    public function logInterview(Application $app, array $details): ApplicationEvent;
}
```

All methods write within the caller's DB transaction (use `DB::transaction` at the caller level, not inside the service).

### 5.3 `ApplicationEvent` model

- `$timestamps = false` (uses `occurred_at`).
- A boot-time static listener on the `updating` event throws `LogicException` — the table is append-only. The only way to correct an event is to write a compensating event.

---

## 6. Error handling & testing strategy

### 6.1 Error-handling deltas from Plan 3

- `ApplicationController@store` returns 422 if the feed match has no linked resume (can't snapshot what doesn't exist).
- `ApplicationController@store` returns 409 on duplicate (unique constraint).
- `ApplicationDocument` upload validates `mimetypes:application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/png,image/jpeg,text/plain` and `max:20480` (20 MB).
- Kanban drop that results in a DB error rolls back both the application update and any half-written event row (single-transaction invariant).

### 6.2 New feature tests

- `ApplicationSaveFromFeedTest` — save-happy-path writes application + status-change event; re-save returns 409; no-resume-on-profile returns 422.
- `ApplicationStatusTransitionTest` — legal transitions work and write event rows; ownership denied for strangers.
- `ApplicationContactCrudTest` — create/update/delete contacts writes events; stranger denied.
- `ApplicationDocumentTest` — upload lands on disk, event written; delete removes disk + DB; mime validation rejects bad files.
- `ApplicationReminderScanTest` — finds due applications, writes reminder events, dedupes same-day reruns.
- `ApplicationEventAppendOnlyTest` — attempts to update a row throw LogicException.

### 6.3 Driver unit tests

- `RemotiveDriverTest` (4 tests) — happy path, salary null handling, validateIdentifier null-accepting, HTTP failure translation.
- `USAJobsDriverTest` (5 tests) — happy path + pagination + auth-header presence + missing credentials → DriverFetchFailed + HTTP failure translation.

### 6.4 E2E

Extend the Playwright spec to include a "save from feed" step after the stage-2 assertion, then navigate to `/applications` and assert the card appears in the `saved` column.

### 6.5 No new admin-shape tests

Admin dashboard is Plan 5. Plan 4's application data is surfaced only to the owning user.

---

## 7. Preconditions, setup, and docs updates

### 7.1 New composer/npm dependencies

- `vuedraggable@^4.1.0` (npm) — kanban drag-and-drop. Vue 3 compatible; minimal surface.
- No new composer packages — file upload is already handled by Laravel core.

### 7.2 New env keys

- `USAJOBS_AUTH_KEY` — API key for USAJobs (free tier).
- `USAJOBS_USER_AGENT_EMAIL` — USAJobs requires a contact email in the User-Agent header.

Both land in `.env.example` as blank sentinels.

### 7.3 CLAUDE.md updates

Three new architecture bullets:
- Applications are append-only in one critical sense: `application_events` rows are never updated. Corrections require compensating events. Controllers write events via `EventWriter`.
- Kanban UI uses `vuedraggable` for drops; each drop is a single PATCH to the application controller that returns the updated row.
- `stage2_snapshot` on `applications` preserves the fit score / narrative / rationale / gaps as they were at save-time, so later reranks (which may overwrite the live `job_matches` row) don't retcon the user's saved context.

New command: `php artisan throughline:reminder-scan-now` — manually triggers the reminder scan for debugging.

### 7.4 README.md updates

"What's here now" section gains: applications pipeline, kanban UI, reminders, Remotive + USAJobs drivers.

---

## 8. Deferred to Plan 5 and beyond

- **Plan 5**: admin dashboard — overview cards, users table + impersonation, analytics with AI cost charts (joining `ai_usage_events.context_id` to profile id to user id, enabled by Plan 3 Task 2's context_id fix), error log, audit log viewer, Horizon mount.
- **Plan 6**: Reverb real-time push (replace feed + resume + nav-badge polling), pgvector ivfflat retuning, brand-string leak guard, settings-driven theming overrides, email notifications for overdue reminders.

---

## 9. Definition of done for Plan 4

- `composer run dev` + `docker compose up -d` runs a working app where:
  - A user with resumes, profiles, and embedded jobs sees a feed, clicks Save on a card, and that application appears in the kanban under `saved`.
  - Dragging the card to `applied` records a status-change event; the detail view shows the timeline.
  - Adding a contact writes a `contact_added` event; uploading a cover letter writes a `document_attached` event.
  - Setting `next_action_at` to yesterday and triggering `throughline:reminder-scan-now` produces a reminder event and the nav badge count updates (after cache TTL or with `?fresh=1` param).
  - Withdrawing an application sets status=withdrawn and records the event; the application stays visible in the collapsed terminal-statuses column.
- All new feature tests, driver tests, and the extended Playwright spec pass. Full PHP suite should land around 165-175 tests.
- CLAUDE.md and README reflect Plan 4.
