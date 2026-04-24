---
title: Feed is empty
summary: You opened your feed and saw nothing. Here's how to figure out why.
order: 2
tags: [feed, troubleshooting]
updated: 2026-04-24
---

An empty feed means the matching pipeline has no jobs to show you for this profile. There are a handful of distinct causes, each with its own fix.

## Quick diagnostic checklist

Before digging in, check these in order — the first few are almost always the cause.

1. **Is your resume ready?** Go to Resumes. The linked resume must show **Ready**, not Parsing or Parse failed.
2. **Is the profile active?** On the profile page, is "Is active" checked? Inactive profiles don't produce matches.
3. **Are there any jobs in the database?** If you're on a fresh install, ingestion may not have run yet.
4. **Are your filters too tight?** A min-comp of $400k with "remote only" in a narrow industry can legitimately match nothing.

## Case 1: resume isn't ready

If the resume linked to your profile isn't in Ready status, no matches exist yet. Matching runs on the resume's embedding, and we haven't generated one.

**Fix:**

- Wait for parsing to finish (usually 30–90 seconds).
- If it's stuck or failed, see [resume stuck parsing](/help/troubleshooting/resume-stuck-parsing).
- Once the resume is Ready, the profile auto-generates matches — refresh the feed after a minute.

## Case 2: profile is inactive

Inactive profiles stop producing new matches. Old matches may still exist but no rerank will run against new jobs.

**Fix:**

- Open the profile, check "Is active", save.
- Visit the feed — a rerank fires on view.

## Case 3: no jobs ingested

Throughline shows jobs from its own database. If the database has no canonical jobs (or none that survived your hard filters), the feed is empty.

**Fix:**

- On a fresh install, ingestion needs to run at least once. The operator does this via `php artisan ingest:jobs` or scheduled cron.
- Check with whoever operates your install — they can confirm the `canonical_jobs` table has rows.

On hosted installs, ingestion is usually running continuously; empty due to this cause is unusual.

## Case 4: hard filters excluded every job

Your profile's hard filters (remote mode, min comp, excluded companies) apply *before* matching. If nothing passes them, you get nothing.

**Fix:** loosen one filter at a time.

- **Remote mode** — if you set "Remote only" and there's not much remote in your target industry, try "Hybrid or remote" or remove the constraint.
- **Min comp** — comp data is sparse. If you set min_comp and most postings don't disclose comp, you may exclude almost everything. Try lowering or removing it and see what comes back.
- **Excluded companies** — check you haven't accidentally excluded a broad category (e.g., excluding "Meta" also excludes jobs posted from any Meta subsidiary).

Change one filter, save, reload the feed, and see what shows up.

## Case 5: score floor too high

The feed defaults to showing fit score ≥ 50. You can set a higher floor in the profile ("Minimum fit score"). If you set it to 80 and your actual matches are mostly in the 60–75 band, you'll see an empty feed.

**Fix:**

- Lower the minimum fit score (or remove it entirely — default is 50).
- Check what fit scores your matches are actually getting — if they're all in the 60s, a floor of 80 explains the empty feed.

## Case 6: the rerank cycle hasn't run yet

If your profile is new, there's a brief window where stage-1 matches exist but stage-2 reranks haven't been generated. During this window, the feed shows cards with "AI rationale pending" — not an empty state.

If you see an empty state rather than pending cards, stage-1 itself hasn't populated yet. Usually only on profile creation.

**Fix:**

- Wait 30–60 seconds after creating the profile, then reload. Stage-1 runs on a queue job.
- If stage-1 doesn't populate at all, the worker may not be running. See "if you're the operator" below.

## Case 7: all your matches are in terminal applications

Once you save a job and take it through to offer/rejected/withdrawn, it won't re-appear in the feed (you've already dealt with it). If you've been thorough and saved *every* match over time, you can run out.

**Fix:**

- Check the applications kanban. If you have 50+ active applications, maybe you don't need more.
- Wait for new ingestion. Fresh jobs show up continuously.
- Broaden your profile (more industries, wider seniority band) to surface more candidates.

## If you're the operator

- Check the queue worker is running. Horizon: `php artisan horizon:status`.
- Check `storage/logs/laravel.log` for `MatchJob` or `RerankShortlist` errors.
- Check the `job_matches` table directly — `select count(*) from job_matches where search_profile_id = X` shows how many stage-1 matches exist.
- If stage-1 rows exist but stage-2 is null, rerank is failing. Logs will show the Claude error.

## Related

- [Understanding the feed](/help/tutorials/understanding-the-feed)
- [How matching works](/help/concepts/how-matching-works)
- [Creating your first search profile](/help/getting-started/04-creating-your-first-search-profile)
