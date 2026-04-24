# User guide

Everything you can do as a signed-in user of Throughline.

## Table of contents

- [Getting an account](#getting-an-account)
- [Your dashboard](#your-dashboard)
- [Resumes](#resumes)
- [Search profiles](#search-profiles)
- [The feed](#the-feed)
- [Applications (kanban)](#applications-kanban)
- [Contacts and documents](#contacts-and-documents)
- [Reminders](#reminders)
- [Email notifications](#email-notifications)
- [Settings and preferences](#settings-and-preferences)

---

## Getting an account

Three sign-in paths:

1. **Email + password** — `/register`. Fill in name + email + password (with confirmation). You're logged in immediately.
2. **Continue with Google** — click the button on the login or register page. Authorize Throughline in Google's dialog. You return logged in.
3. **Continue with GitHub** — same flow via GitHub.

If OAuth buttons aren't visible, the admin hasn't configured OAuth credentials yet. Email + password still works. See the [external accounts doc](external-accounts-setup.md#google-oauth) if you're self-hosting.

**The first user** on a fresh install is automatically promoted to admin. If that's you, after signup you'll see an "Admin" link in the left nav alongside the user-facing pages.

### Linking multiple providers to one account

If you first sign in with email + password, then click "Continue with Google" using the same email, Throughline recognizes the email and links the Google identity to your existing account. Both paths will then log you into the same account. Same for GitHub. The `social_accounts` table stores each linked provider identity.

---

## Your dashboard

The dashboard is the landing page after login (`/dashboard`). It's a small summary page — the real work happens in the feature-specific pages.

Left navigation:

- **Dashboard** — the page you're on.
- **Resumes** — upload and manage resumes.
- **Search profiles** — define what roles you're looking for.
- **Applications** — your kanban pipeline.
- **Admin** — only visible if you're an admin (see [admin guide](admin-guide.md)).

A small red badge may appear next to **Applications** if any of your saved jobs have a `next_action_at` due date in the past. This updates in real time as reminders are processed.

---

## Resumes

Throughline supports multiple resume variants per user. Different career arcs deserve different resumes.

### Uploading a resume

1. Go to **Resumes** in the left nav.
2. Click **Upload resume**.
3. In the dialog: give it a descriptive name ("Staff frontend", "Eng manager", "Early-career PM"), select a PDF or DOCX file, click **Upload**.
4. You're redirected to the resume detail page. The page shows a status indicator:
   - **Parsing your resume.** Text extraction + Claude structured-data parse in progress. Usually sub-minute.
   - **Parsed. Generating embedding…** Claude finished; OpenAI embedding request in flight.
   - **Ready to match against jobs.** Done.
5. The page pushes the "Ready" state live via WebSocket (no refresh needed). If Reverb isn't running, a 30-second polling fallback catches up.

Accepted formats: PDF (10 MB max) and DOCX (10 MB max). For DOCX, all plain-text content is extracted. For PDF, extraction depends on the source — LaTeX-style PDFs with embedded text extract well; scanned-image PDFs do not.

### What Claude extracts

Claude parses the raw text and produces structured JSON with these fields:

- `name` — your name
- `titles` — an array of job titles you've held
- `companies` — an array of company names
- `skills` — an array of technical and soft skills
- `years_experience` — integer or null

The raw parsed text (full resume content) is stored alongside. This is what OpenAI embeds for matching; the structured JSON is currently used only for display on the resume detail page (v1 doesn't use it for matching — that's the embedding's job).

### When parsing fails

If Claude returns unparseable JSON, or if text extraction itself throws (corrupt PDF, missing Poppler binary, etc.), the status becomes **Parse failed**.

Options:

- **Retry parse** — re-queues the `ParseResume` job. Useful if it was a transient Claude hiccup.
- **Paste text manually** — coming in a future version. For now, re-upload or retry.

### Multiple resumes and the default

You can have any number of resumes. Exactly one is marked **default**. The first resume you upload is automatically default; for subsequent uploads, use **Set as default** on the detail page.

Linking a resume to a search profile is separate from setting it as default — profiles have their own `resume_id` FK. The default is just a convenience for UI defaults and a fallback.

### Deleting a resume

From the detail page, **Delete**. This:

- Soft-deactivates: no, actually hard-deletes the database row.
- Removes the file from storage.
- Any `search_profiles` that linked this resume have their `resume_id` set to NULL (the FK is `ON DELETE SET NULL`). The profile keeps existing but stops producing matches until you link a new resume.
- Any `applications` that snapshotted this resume at save-time keep their snapshot — the stage-2 rationale is preserved.

---

## Search profiles

A search profile is "what kind of job am I looking for right now, scored against which resume". You can have multiple: e.g., "Staff frontend remote-only", "Backend manager hybrid NYC", "Early-career PM anywhere".

### Creating a profile

1. Go to **Search profiles → New profile**.
2. Fill in the form:
   - **Name** — a label you'll recognize. Shows up in the feed header and in admin views.
   - **Linked resume** — the resume that gets embedded against this profile's jobs. Without a resume, matches can't generate.
   - **Role keywords** — comma-separated. These aren't hard filters; they're surfaced to Claude during rerank so the narrative + gaps reflect your target.
   - **Locations** — cities/regions/countries you'll consider. Comma-separated.
   - **Remote mode** — hard filter. One of: `any`, `remote_only`, `hybrid_ok`, `onsite`.
   - **Comp min** — hard filter. Jobs with `comp_min` below this value are excluded. Jobs with no comp data (null `comp_min`) pass through.
   - **Comp currency** — e.g., `USD`. For v1, this is informational; comp filtering compares numbers without currency conversion.
   - **Industries** — informational for rerank (comma-separated).
   - **Seniority levels** — informational.
   - **Excluded companies** — hard filter. Jobs whose company_name matches any entry via `ILIKE '%company%'` are filtered out.
   - **Is active** — uncheck to pause matching for this profile without deleting it.
3. Save. You're redirected to the profile list.

### Why some fields are hard filters and others aren't

Hard filters apply at the database query level in `HardFilterBuilder`. They reduce the candidate set before cosine similarity even runs. The three hard filters are **remote mode**, **comp min**, and **excluded companies** — things that are either a deal-breaker or structured data.

The other fields (role keywords, industries, seniority levels) are semantic hints. They end up in the Claude rerank prompt, helping Claude produce more relevant narrative + gap output. A mismatched seniority level won't exclude a job from your feed; it'll show up with gaps pointing out the mismatch.

### Editing

Click the profile name on the list page. Same form. Saving re-runs `RecomputeStage1ForResume` (if you change `resume_id` or hard filters) — your `job_matches` rows get regenerated with fresh stage-1 scores.

### Deleting

From the edit page, **Delete profile**. This cascades: all `job_matches` for this profile are deleted. Applications you've already saved are preserved (their `search_profile_id` FK is `ON DELETE SET NULL`).

---

## The feed

For each active search profile you have, there's a ranked feed of jobs at `/feed/{profile}`.

### Card anatomy

Each card shows:

- **Title + company** — the job role.
- **Fit score badge** (0–100) — Claude's assessment of how well this role fits you. Oxblood solid for ≥80, oxblood tint for 60–79, muted for <60 or null.
- **Narrative subtitle** — a one-sentence framing of how this role fits your career arc ("Natural next step after your Stripe tenure — extends platform ownership without a management pivot.").
- **Location / remote mode / comp / posted date** — the essentials.
- **View posting →** — opens the original posting in a new tab.
- **Save** button — saves to your applications pipeline (status=saved).
- **Rationale** — 2–4 sentence explanation of the fit.
- **Gap chips** — color-coded by severity:
  - Blocker (oxblood): a hard requirement you don't meet.
  - Stretch (muted): a nice-to-have you partially cover.
  - Minor (outlined): a minor gap, probably easy to address.

### How ranking works

Two stages:

1. **Stage-1 cosine similarity.** Your resume's embedding vs each job's embedding. Runs on every ingestion. Jobs below `site_settings.stage1_score_floor` (default 0.2) don't even produce a match row. Stage-1 is used when nothing fresher is available.

2. **Stage-2 Claude rerank.** The top-K stale matches (default 20) go to Claude in a single batch. Claude returns fit_score, rationale, gaps, and narrative for each. The feed then orders by `stage2_score DESC NULLS LAST, stage1_score DESC` — fresh-reranked results rise above not-yet-reranked ones.

When you open the feed, if the top-K has stale rows (no stage-2 data, or older than `stage2_ttl_hours` — default 24h) AND `rerank_enabled` is on, a synchronous Claude call fires on your page render. Budget is ~3 seconds. When it completes, the feed reloads and your cards show stage-2 content.

**Nightly refresh** — daily at `nightly_rerank_hour_utc` (default 04:00 UTC), `NightlyRerankRefresh` queues a rerank for the top-50 of every active profile. Morning feeds are fast because the first page is already fresh.

### Real-time updates

If you're looking at the feed and a nightly rerank completes for your profile (or a sync rerank fires from another tab), the feed receives a `FeedMatchReranked` event and reloads the matches in place — no action from you.

### Debug view

`/feed/{profile}?raw=1` bypasses rerank entirely. Useful if you want the fast path, or if stage-2 is broken and you want to see stage-1 order. Admins viewing feeds can also append `&debug=1` to see raw stage-1 scores in each card.

### "AI rationale temporarily unavailable"

If `RerankShortlist` hits `RerankFailed` (Claude returned unparseable JSON, or a required field was missing), a sentinel value is written to the affected matches. The card shows a muted "AI rationale temporarily unavailable — refresh shortly" state until the next rerank cycle succeeds.

### Why the feed is empty

If you see "No matches yet":

1. Your search profile has no linked resume, OR
2. Your resume hasn't finished parsing + embedding yet, OR
3. No canonical jobs in the database have embeddings yet (check admin → Sources → Fetch now), OR
4. Your hard filters are too strict — try loosening `remote_mode` to `any` or removing the `comp_min`.

---

## Applications (kanban)

When you save a job from the feed, it lands in your applications pipeline. Track through 8 statuses via drag-and-drop.

### The kanban

`/applications` shows columns:

- **Saved** — just stashed; haven't applied yet.
- **Applied** — you've submitted an application.
- **Phone screen** — first-round call scheduled/complete.
- **Technical** — technical interview stage.
- **On-site** — on-site interview (or equivalent final round).
- **Offer** — offer received.
- **Rejected** — didn't move forward.
- **Withdrawn** — you pulled out.

The last three (offer / rejected / withdrawn) are collapsed by default in a "terminal statuses" section. Click to expand.

### Saving from feed

Click **Save** on any feed card. Throughline:

1. Creates an `applications` row with status=saved, linked to the canonical job, the profile you viewed from, and the profile's currently-linked resume.
2. **Snapshots the stage-2 rationale** into `applications.stage2_snapshot`. This is key — if the rerank later changes (maybe your resume is updated), your saved application keeps the rationale from the moment you saved it. History isn't retconned.
3. Writes the first `application_events` row (type=status_change, from=null, to=saved).

If you try to save the same job twice, you get a 409 with "Already saved" — click on the feed card's **Saved** indicator to jump to the existing application.

### Dragging through statuses

Drag a card from one column to another. Throughline fires a PATCH request, updates the `applications.status`, and writes a `status_change` event. The UI reverts visually if the request fails (e.g., stale session).

Transitioning to **Applied** also sets `applied_at` if it wasn't already set.

### The detail view

Click a card to open its detail page. Here you can:

- **Read the rationale at save-time** — the Claude output from when you saved, preserved against any later rerank.
- **Edit notes** — free text, writes a `note` event on save.
- **Change status** — dropdown, writes a `status_change` event.
- **Set a next action** — pick a date for the reminder system (see below).
- **Manage contacts** — recruiters, hiring managers, interviewers (see [contacts](#contacts-and-documents)).
- **Attach documents** — cover letters, portfolios, resume snapshots.
- **See the event timeline** — every action on this application, newest first.
- **Withdraw** — status → `withdrawn`. This doesn't delete the application; it preserves history and moves the card to the terminal section.

### The event timeline

Every mutating action writes to `application_events` — append-only. The timeline shows:

- Status changes with from/to
- Notes you added (preview of the first ~80 chars)
- Contacts added (with their name + role)
- Documents attached (with filename + kind)
- Reminders logged by the scan job

You can't edit or delete events. To "correct" a mistake, do the compensating action — e.g., if you accidentally marked a rejection, drag back to Applied. The timeline will show both transitions.

---

## Contacts and documents

Inside an application detail view.

### Contacts

Recruiter, hiring manager, interviewer, referral, or other. Each contact has:

- **Name** (required)
- **Role** (required — one of the 5 above)
- **Email** (optional)
- **Phone** (optional)
- **LinkedIn URL** (optional)
- **Notes** (optional — free text)

Adding a contact writes a `contact_added` application event.

### Documents

Attach cover letters, resume snapshots, portfolios. Supported types:

- `cover_letter`
- `resume_snapshot` — a specific resume version for this application
- `portfolio`
- `other`

MIME whitelist: PDF, DOCX, PNG, JPEG, plain text. Max 20 MB.

Uploading writes a `document_attached` event.

Files are stored on the configured disk (`storage/app/private/applications/{user_id}/{application_id}/` in dev; S3 bucket in production). They're not publicly accessible — downloads stream through a controller that checks ownership.

Deleting a document removes it from both the database and the file storage.

---

## Reminders

Every application can have a **next_action_at** — a timestamp for when you should follow up.

### Setting a next action

On the application detail page, set the **next action** date picker. This updates `applications.next_action_at`.

### How reminders fire

Daily at `site_settings.reminder_scan_hour_utc` (default 09:00 UTC), `ApplicationReminderScan` runs. For each non-terminal application with `next_action_at <= now()`:

- Writes a `reminder` event (deduped per-day: only one reminder event per `(application_id, date)`).
- Fires a `ReminderBadgeUpdated` broadcast event — your nav badge updates live to show the current count of due applications.
- If you have `notification_email_enabled = true`, queues a `SendReminderEmail` job.

### The nav badge

A small red pill appears next to **Applications** in the nav when you have ≥1 due application. Click to jump to the list, filtered to due applications.

The badge count is cached in Redis for 60 seconds per user — so a page refresh may lag slightly. The real-time push path (via `ReminderBadgeUpdated`) bypasses the cache for immediate updates.

### Clearing a reminder

Change or clear `next_action_at`. Once it's in the future (or null), the application is no longer counted in the badge.

---

## Email notifications

When your reminders fire AND you're opted in, you get a daily digest email listing all applications due that day.

### Opting out

Currently (v1), the opt-out is per-user at the database level: `users.notification_email_enabled`. There's no user-facing settings toggle yet in the UI — if you want to opt out, ask an admin to set the column to `false`, or an artisan one-liner from a shell:

```bash
php artisan tinker
>>> User::where('email', 'you@example.com')->update(['notification_email_enabled' => false]);
```

Future versions will expose this in a profile settings page.

### The email

Subject: "Applications due for follow-up"

Body: A list of applications due, each with:

- Title + company
- Link to the application detail page
- Link to the overall applications pipeline

Rendered from `resources/views/mail/reminder-due.blade.php`. The `{{ site_name }}` in the template honors your admin's brand overrides.

### Dedupe

If `ApplicationReminderScan` retries (queue failure + rerun), the per-day Redis cache key `reminder_email_sent:{user_id}:{date}` prevents a second email from going out. You'll only ever get one reminder email per day regardless of queue retries.

---

## Settings and preferences

V1 doesn't have a user-facing settings page. Profile attributes like `notification_email_enabled`, name, email, password are editable either:

- Via the Laravel starter kit's built-in `/settings` routes (if the installed starter kit version wires them up)
- Via an admin in the `/admin/users` detail page

If self-service settings are something you need, that's a future-plan item.

---

## Troubleshooting

### "My resume says 'Parsing…' forever"

Check the queue listener is running. Locally, `composer run dev` starts it. If you stopped the dev server mid-parse, the job may be in-flight or waiting. Restart, or click **Retry parse** on the resume detail page.

Also verify your `ANTHROPIC_API_KEY` and `OPENAI_API_KEY` in `.env` — invalid keys cause the job to fail silently; check `storage/logs/laravel.log` for `Unauthorized` messages.

### "The feed is empty"

See [Why the feed is empty](#why-the-feed-is-empty).

### "Cards have 'AI rationale pending'"

The first time you view a feed for a given profile, stage-2 rerank hasn't happened. The page triggers a sync rerank (~3 seconds). If that takes longer than the page timeout, you might see the pending state; refresh to see the rerank output.

If all cards show "AI rationale temporarily unavailable", Claude keeps failing. Try again in a few minutes — rate limits may have spiked. If it persists, ask an admin to check the error log.

### "I clicked Save but nothing happened"

Check if your search profile has a linked resume. Without it, saving returns a 422 "Link a resume first" error. The feed card's Save button doesn't always surface the error clearly — open your browser devtools Network tab to see the response.

### "The kanban won't let me drag"

Two possibilities:

1. Your session expired. Refresh the page.
2. The card you're trying to drag is the one you're currently viewing the detail of. Close the detail panel first.

Otherwise it's probably a JavaScript error — open devtools console.

---

## What's next

- Deep-dive: [Architecture](architecture.md) if you want to understand how it all works under the hood.
- [Admin guide](admin-guide.md) if you're the operator for a self-hosted install.
