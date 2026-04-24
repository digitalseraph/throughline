---
title: Save a job from the feed
summary: Move a role from your feed into the applications kanban.
order: 3
tags: [feed, applications, save]
updated: 2026-04-24
---

On any card in your feed, click **Save**. That's it.

Throughline does several things atomically:

1. Creates a new application with status `saved`.
2. Links it to the canonical job, to the search profile you viewed from, and to the resume currently linked to that profile.
3. **Snapshots the stage-2 rerank output** (fit score, rationale, gaps, narrative) into the application's `stage2_snapshot` field. This is yours forever — future reranks won't overwrite it.
4. Writes the first `application_events` row (`status_change`: null → saved).

The card on the feed re-renders with a **Saved** indicator instead of the Save button.

## Finding it again

Open **Applications** in the left nav. Your new application is in the **Saved** column of the kanban.

## "Already saved" / 409

If you try to save the same canonical job twice — whether from the same profile or a different one — Throughline recognizes the duplicate and returns a 409 status. The feed card surfaces the "Already saved" state; click it to jump to the existing application detail page.

One user has at most **one application per canonical job**. If the same job appears in two of your search profiles' feeds, saving from the second doesn't create a duplicate; it points back to the first.

## "Link a resume first" / 422

If the search profile you're viewing doesn't have a linked resume, Throughline can't snapshot stage-2 context — because there was no stage-2 rerank without a resume. You'll get a 422 with a message prompting you to link a resume.

Fix: open the profile's edit page, link a resume, save. Come back to the feed.

## Related

- [Tracking an application end-to-end](/help/tutorials/tracking-an-application-end-to-end)
- [Can't save from feed](/help/troubleshooting/cant-save-from-feed)
