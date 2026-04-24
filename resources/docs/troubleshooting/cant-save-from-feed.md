---
title: Can't save a job from the feed
summary: The Save button didn't work, or you got an error. Here's why and how to fix it.
order: 4
tags: [applications, save, troubleshooting]
updated: 2026-04-24
---

Saving from the feed is the primary way jobs move into your application pipeline. If it's not working, here's what to check.

## Case 1: "You've already saved this job"

You clicked Save on a job you already have an application for. Throughline enforces one application per `(user, canonical_job)` — a second save returns a 409 with a link to the existing application.

**What to do:**

- Click the link in the toast to jump to the existing application.
- If you don't remember saving it, check the applications kanban. It may be in a terminal column (offer, rejected, withdrawn) and collapsed by default.
- If you saved it in another profile, it's still the same canonical job — one application total per job across all your profiles.

This is intentional. See [how the application pipeline works](/help/concepts/how-the-application-pipeline-works).

## Case 2: the Save button is grayed out

A disabled Save button means the feed card doesn't have enough to create a valid application. Usually because:

- The canonical job has been deleted or de-canonicalized since you loaded the feed.
- Your current resume isn't available (unusual — possible if it was deleted in another tab).

**What to do:**

- Refresh the feed. The card will disappear if the job is gone, or re-enable if the resume is back.
- If the Save button is still disabled after a reload, reach out to the operator — possibly a data-integrity issue.

## Case 3: "Save failed" toast with no error details

Something went wrong server-side. Common causes:

- The database is temporarily unavailable.
- A queue-worker dependency failed (the stage-2 snapshot couldn't be copied).

**What to do:**

- Retry. Transient issues usually clear on second attempt.
- Check the rest of the app — does the feed load? Does another Save succeed? If *everything* is broken, the operator needs to look at logs.
- Report to the operator with the time you tried, so they can correlate logs.

## Case 4: "No default resume" or "Resume missing" error

Saving creates an application that references a specific resume (so gap analysis at save-time is accurate). If the profile has no linked resume, the save fails.

**What to do:**

- Open the profile. Check that "Linked resume" is set to one of your Ready resumes.
- If the linked resume was deleted, pick a new one and save the profile.
- Retry the save.

## Case 5: save worked but no application appears

You clicked Save, saw a success toast, but the kanban is empty.

**What to check:**

- Reload the applications page. Inertia may not have invalidated the cache.
- Check the "Saved" column specifically. Default view is all active columns.
- If nothing's there, check the collapsed terminal section — you may have accidentally dragged it to Withdrawn.

## Case 6: save button does nothing at all (no toast, no error)

This usually means a JavaScript error on the page is preventing the click handler from running.

**What to try:**

- Open the browser devtools Console (F12 in Chrome/Edge/Firefox).
- Click Save again.
- Look for a red error in the console. Common ones:
  - `CSRF token mismatch` — your session expired. Refresh the page and log back in.
  - `Network error` — connection dropped. Check your internet.
  - A Vue/Inertia error — report to the operator with the exact message.

## If you're the operator

- Check `storage/logs/laravel.log` for `SaveApplicationAction` errors.
- The most common database-level cause is the unique constraint on `(user_id, canonical_job_id)` — which is deliberate. The controller handles this and returns 409, but a malformed request can leak as 500.
- Transaction failures during the stage-2 snapshot copy are rare but logged.

## Related

- [Save a job from the feed](/help/how-to/save-a-job-from-the-feed)
- [How the application pipeline works](/help/concepts/how-the-application-pipeline-works)
- [Tracking an application end-to-end](/help/tutorials/tracking-an-application-end-to-end)
