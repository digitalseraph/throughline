---
title: Creating your first search profile
summary: A search profile is "what roles, where, at what bar". Here's how to set one up.
order: 4
tags: [search-profiles, onboarding]
updated: 2026-04-24
---

A **search profile** is a named search. It carries the hard constraints (remote mode, compensation floor, excluded companies) and the semantic context (role keywords, industries, seniority levels) that Throughline uses to produce a ranked feed of jobs for you.

You can have as many profiles as you want. Different career arcs get different profiles — one for your "manager track" search, another for "staff engineer at a series B", a third for "interesting early-career-friendly startups". Each has its own linked resume and its own feed.

## Create one

1. Open **Search profiles** in the left nav.
2. Click **New profile**.
3. Fill in:

### Name

Something you'll recognize in a list. "Staff frontend search" or "Remote-only backend manager" both work better than "Search 1".

### Linked resume

The resume that Throughline embeds and scores against this profile's jobs. Without a linked resume, no matches will be generated.

If you only have one resume, pick it. If you have multiple, pick the one most relevant to the kinds of roles this profile targets.

### Role keywords

Comma-separated. Something like `staff, principal, frontend, react, performance`.

These are **not hard filters** — a job whose title doesn't match your keywords isn't auto-excluded. They're hints passed to Claude during stage-2 rerank so the narrative and gap analysis reflect what you're actually hunting for. A profile targeting "staff engineer" gets rationale framed in staff-engineer terms; a profile targeting "engineering manager" gets rationale framed around team leadership.

### Locations

Cities, regions, or countries you'll consider. Comma-separated: `NYC, San Francisco, Remote - US`.

Informational in v1. Future versions may use this for stricter geographic filtering.

### Remote mode

**This is a hard filter.** One of:

- **Any** — no filter on remote status.
- **Remote only** — only roles flagged `remote_only` by the driver match.
- **Hybrid OK** — roles that are `remote_only` or `hybrid_ok` both match.
- **On-site** — only onsite roles.

### Comp minimum

**Hard filter.** Jobs with a declared `comp_min` below your threshold are excluded. Jobs with no comp data (a lot of postings don't declare) pass through — we'd rather show you a role without comp data than filter it out.

### Comp currency

Informational in v1. The comp-min filter compares numbers without currency conversion; if you're setting a USD floor and want to see UK roles in GBP, consider a lower threshold or leave the filter off.

### Industries

Comma-separated: `fintech, healthcare, developer tools`.

Informational. Passed to Claude during rerank.

### Seniority levels

Comma-separated: `staff, principal, senior`.

Informational. Helps Claude frame the narrative.

### Excluded companies

**Hard filter.** Jobs whose `company_name` matches any entry (case-insensitive partial match) are excluded from your feed. Useful if there are companies you've already ruled out or worked at.

### Is active

Checked by default. Uncheck to pause matching for this profile without deleting it. Useful if you want to come back to a search later without losing the configuration.

## What happens after you save

1. Your profile is persisted.
2. If a linked resume is set, Throughline runs the stage-1 match fan-out — every job that's currently in the database gets scored against your resume and, if it passes your hard filters and the similarity floor, a row lands in your match table.
3. Your feed at `/feed/{profile}` immediately has results (if any jobs are in the database — see [reading your first feed](/help/getting-started/05-reading-your-first-feed) for what happens if it's empty).

## Editing

You can change any field on a profile later. Changing the linked resume or any hard filter triggers a fresh stage-1 fan-out against your resume — the old match rows are replaced.

## Next

- [Read your first ranked feed](/help/getting-started/05-reading-your-first-feed)
