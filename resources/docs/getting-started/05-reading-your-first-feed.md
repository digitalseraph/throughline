---
title: Reading your first ranked feed
summary: What you're looking at on the feed, how to interpret the fit score and gaps, and what to do with a role you like.
order: 5
tags: [feed, onboarding, matching]
updated: 2026-04-24
---

Each active search profile has a ranked feed of jobs at `/feed/{profile-id}`. This is where Throughline delivers on its promise — a prioritized list of roles that fit the arc you're on, with honest context about each one.

## What a card shows you

Every card on the feed has:

- **Title and company** at the top.
- **Fit score badge** (0–100), styled by threshold:
  - **Oxblood solid** for scores ≥80 (strong match)
  - **Oxblood tint** for 60–79 (respectable, worth reading)
  - **Muted** for <60 or not-yet-scored
- **Narrative subtitle** — one sentence framing how this role fits your career arc. Claude wrote this for you, grounded in your resume.
- **Location, remote mode, comp range, posted date** — the essentials.
- **View posting →** link — opens the original in a new tab.
- **Save** button — moves the role into your applications kanban.
- **Rationale** — 2–4 sentences from Claude explaining the fit.
- **Gap chips** — what might be missing or stretch-y. Color-coded:
  - **Red (blocker)** — a hard requirement you don't meet. Read it carefully before applying.
  - **Muted (stretch)** — nice-to-have you partially cover. Often not a dealbreaker.
  - **Outlined (minor)** — small gap, probably easy to address.

## How ranking works (the short version)

There are two stages:

1. **Stage-1 cosine similarity.** Your resume's embedding vs each job's embedding, bounded by your hard filters. Fast, cheap, runs on every ingestion.
2. **Stage-2 Claude rerank.** The top-K jobs by stage-1 score (default 20) go to Claude as a batch. Claude returns fit score, rationale, gaps, and narrative for each.

When you open the feed, if the top of the list has stale rerank data, Throughline dispatches a synchronous rerank call. It takes about 3 seconds — then the page reloads with fresh stage-2 content.

A **nightly job** also pre-reranks the top-50 per active profile at 4 AM UTC by default. So your morning feed is fast — the top roles are already fresh.

See [how matching works](/help/concepts/how-matching-works) for the longer version, or [what is fit score](/help/concepts/what-is-fit-score) for how to read the number.

## What to do when you see a role you like

Click **Save**. The role lands in your applications pipeline under "Saved". Throughline snapshots the rationale *as of this moment* into the application — if the rerank later changes because you updated your resume, your saved context isn't retconned.

From the applications page you can then drag the card through the kanban: Saved → Applied → Phone screen → and onward.

## What to do when the feed is empty

A few possible causes:

1. **Your resume isn't ready yet.** Go back to Resumes — does the active resume say "Ready to match"? If not, wait for it to finish, or fix a failed parse.
2. **No jobs are ingested.** Fresh installs start with zero jobs. An admin needs to run a fetch on one or more job sources before anything flows into the feed. If you're self-hosting, go to `/admin/job-sources` and click **Fetch now** on a seeded Greenhouse board like `stripe` or `notion`. Wait a minute for the pipeline to complete.
3. **Your hard filters are too strict.** If you set a `comp_min` of $500k and required `remote_only`, very few roles will make it through. Loosen filters and see.
4. **Your stage-1 score floor is cutting everything.** An admin can tune `stage1_score_floor` — the default of 0.2 is permissive, but if yours is set higher than the seeded default, it may be suppressing matches.

See [feed is empty](/help/troubleshooting/feed-is-empty) for a deeper troubleshooting walk.

## "AI rationale pending" or "temporarily unavailable"

If a card shows **AI rationale pending**, the rerank hasn't completed yet. Refresh in a few seconds.

If it shows **AI rationale temporarily unavailable**, Claude returned unparseable output for that batch. The system will retry on the next rerank cycle; no action from you is needed.

## That's it for the guided tour

From here, dig into whichever area is most useful for you:

- [Tutorials](/help/tutorials) — deeper walkthroughs
- [How-tos](/help/how-to) — specific tasks
- [Concepts](/help/concepts) — understanding the internals
- [FAQ](/help/faq/common-questions) — quick answers
- [Troubleshooting](/help/troubleshooting) — when something's wrong
