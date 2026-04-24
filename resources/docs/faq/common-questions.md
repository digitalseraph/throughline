---
title: Common questions
summary: The answers people need most often.
order: 1
tags: [faq]
updated: 2026-04-24
---

## General

### What is Throughline?

A job-discovery tool that reads your resume and produces a ranked feed of roles with Claude-generated fit scores, rationales, and honest gap analysis. You save the ones you like into a kanban pipeline and track through to offer or rejection. More in [what is Throughline](/help/getting-started/01-what-is-throughline).

### Who is Throughline for?

People whose careers already have a shape (early-career to staff to leadership) who want their next role to be consistent with that shape. If your approach to job hunting is "spray and pray across 300 roles", Throughline is probably the wrong tool — it's built for considered selection.

### Does Throughline auto-apply to jobs?

No. Every application is a deliberate click by you. Auto-apply is not on the roadmap.

### Does Throughline write cover letters or tailor my resume?

No. Bring your own resume(s); Throughline reads them. Resume tailoring per posting is out of scope for v1.

### Is Throughline free?

Depends on where you got it. The code is open source. Self-hosting costs whatever you pay for Postgres, Redis, S3, and the AI API calls (OpenAI + Anthropic). If you're on a hosted install, check with whoever operates it.

## Account and sign-in

### Can I sign in with Google / GitHub?

Yes, if the install has OAuth configured. Look for "Continue with Google" / "Continue with GitHub" buttons on the login and register pages. See [sign in with Google or GitHub](/help/how-to/sign-in-with-google-or-github).

### Can I use multiple sign-in methods on one account?

Yes. Start with email + password, then link Google or GitHub later. All sign-in methods point at the same account if the email matches.

### I forgot my password. How do I reset it?

The login page has a "Forgot password" link. Use it.

### How do I delete my account?

V1 doesn't have a self-service account deletion UI. Ask an admin (if you're on a hosted install) or use `tinker` if you're self-hosting. Future versions will expose self-service.

## Resumes

### What file formats are supported?

PDF and DOCX. Up to 10 MB.

### Why does my resume say "Parse failed"?

Usually one of:

- The PDF is scanned images (no embedded text) — we don't OCR.
- The DOCX has unusual formatting that broke text extraction.
- Claude returned unparseable output for the content.

Try re-uploading or clicking **Retry parse** on the detail page. See [resume stuck parsing](/help/troubleshooting/resume-stuck-parsing).

### How many resumes can I have?

As many as you want. Different career arcs deserve different resumes. See [managing multiple resumes](/help/tutorials/managing-multiple-resumes).

### What does "Set as default" do?

Marks one resume as your default. Doesn't affect matching — profiles link to specific resumes by id, not by default-ness. It's a convenience for UI defaults.

## Search profiles

### What's the difference between hard filters and semantic hints?

Hard filters (remote mode, comp min, excluded companies) apply at the database-query level. Jobs that don't pass are excluded before any similarity computation.

Semantic hints (role keywords, industries, seniority levels) are passed to Claude during rerank. They influence the narrative and gap analysis but don't exclude jobs.

### Can I have multiple active profiles?

Yes. As many as you want. Each has its own feed.

### What happens if I change the linked resume?

All `job_matches` for that profile are regenerated using the new resume's embedding. The old matches are replaced. Stage-2 data on the new matches is initially blank; the next feed view triggers a fresh rerank.

### Can I pause a profile without deleting it?

Yes. Uncheck "Is active" and save. The profile is preserved but stops producing matches until you re-enable.

## Feed

### Why is my feed empty?

Several possible causes — resume not ready, no jobs ingested, filters too strict, score floor too high. See [feed is empty](/help/troubleshooting/feed-is-empty).

### What do the colors on the fit score badge mean?

- **Oxblood solid** — strong match (≥80)
- **Oxblood tint** — respectable (60–79)
- **Muted** — weaker or not yet scored

### What's the difference between stage-1 and stage-2 scores?

Stage-1 is cosine similarity (0–1), internal. Stage-2 is Claude's fit score (0–100), displayed on cards. More in [how matching works](/help/concepts/how-matching-works).

### Can I see the stage-1 score?

Admins can add `?debug=1` to the feed URL. Regular users don't see it on cards.

### Can I sort the feed differently?

Not directly in the UI. The default is by stage-2 fit score (with stage-1 as tiebreaker). Use `?raw=1` to bypass rerank and see stage-1 order.

### Why does the same job score differently in two of my profiles?

Because each profile has its own linked resume. The embedding context is different, so Claude's judgment is different. See [understanding the feed](/help/tutorials/understanding-the-feed).

### What does "AI rationale temporarily unavailable" mean?

Claude returned unparseable output for that card's rerank batch. A sentinel was written to prevent tight retry loops. The next rerank cycle will try again.

## Applications

### What happens when I save a job?

An application is created in "Saved" status, linked to the job, your profile, and your active resume. The stage-2 rationale is snapshotted into the application. See [save a job from the feed](/help/how-to/save-a-job-from-the-feed).

### Can I save the same job twice?

No — unique constraint on `(user, canonical_job)`. A second save returns 409 with a pointer to the existing application.

### What are terminal statuses?

Offer, rejected, and withdrawn. Applications in terminal statuses are collapsed in the kanban, don't count toward your reminder badge, and aren't considered "active".

### Can I un-withdraw an application?

Yes. Drag it back into an active column. The event log shows both the withdraw and the un-withdraw.

### Why can't I edit an event on the timeline?

Events are append-only. To correct something, do the compensating action — both events will appear in the timeline.

### How long are saved applications kept?

Indefinitely. There's no auto-expire. They stay until you manually delete (not supported in v1) or the whole account is deleted.

## Reminders

### When do reminder emails fire?

Daily at 09:00 UTC (configurable per-install). Only on days when at least one of your applications is due.

### How do I opt out of emails but keep the in-app badge?

Ask an admin to set `notification_email_enabled = false` on your account, or use tinker if you have shell access. See [opt out of email reminders](/help/how-to/opt-out-of-email-reminders).

### Will I get duplicate emails?

No. There's a per-day-per-user dedupe via Redis. At most one reminder email per day.

### The nav badge shows 3 but my Applications page is empty. Why?

Likely a cache timing issue — the badge count is cached for 60 seconds. Refresh and it should update.

## Admin

### I need admin access. How do I get it?

If you're the first user on a fresh install, you're admin automatically. Otherwise ask an existing admin to promote you via the admin Users page.

### Can admins see my data?

Admins can see metadata about all users — your email, last login, role, resume count, application count. They can also view and edit your search profile configurations if they impersonate you.

Admins **cannot** see the content of your resumes, applications, notes, contacts, or documents without impersonating you — and every impersonation writes an audit log entry that records who did it when.

### Can admins impersonate other admins?

No. Impersonation of admin-role users is explicitly blocked (403). Nested impersonation is also blocked.

## Data and privacy

### Where is my data stored?

Depends on the install. The standard setup is Postgres for the database, Redis for cache/queue/session, and S3 (or a local disk in dev) for resume and application documents. Ask whoever operates your install.

### Does Throughline share my data with third parties?

The content we send to third parties:

- **Your resume text** → OpenAI for embedding, Anthropic (Claude) for parsing and rerank.
- **Job descriptions** → OpenAI for embedding, Anthropic for rerank (paired with your resume during rerank).

That's it. No analytics trackers, no ad networks, no data brokers.

### Can I export my data?

V1 doesn't have a self-service export. Self-hosters can run arbitrary SQL. A data-export UI is planned.

## Technical / self-hosting

### Can I self-host?

Yes. See the [GitHub repo](https://github.com/digitalseraph/throughline) and the production-readiness doc in `docs/production-readiness.md`.

### What does it cost to run?

Varies wildly. A rough estimate for a small personal install:

- Postgres (managed): $15–30/month
- Redis (managed): $10–15/month
- S3 (usage-based): $1–5/month for modest traffic
- AI APIs: $5–20/month depending on usage (embed is cheap; rerank is the bulk)
- Compute (VPS): $10–20/month

So roughly $40–90/month for a personal install. Scales with users and ingestion volume.

### What do I need to run it?

See the [external accounts setup](https://github.com/digitalseraph/throughline/blob/main/docs/external-accounts-setup.md) doc. Minimum: OpenAI + Anthropic keys. Everything else is optional for basic functionality.

## Still stuck?

- [Troubleshooting](/help/troubleshooting) for specific issues.
- [Tutorials](/help/tutorials) for deeper walkthroughs.
- Check the [GitHub issue tracker](https://github.com/digitalseraph/throughline/issues) for known bugs.
