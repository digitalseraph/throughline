---
title: How the application pipeline works
summary: The 8-status state machine, what "terminal" means, and why the event log is append-only.
order: 4
tags: [applications, kanban, concepts]
updated: 2026-04-24
---

Applications in Throughline are a small state machine. The kanban is the visual representation. Understanding the model helps you use it well.

## The 8 statuses

```
saved → applied → phone_screen → technical → onsite → offer
                                                    ↘ rejected
                                                    ↘ withdrawn
```

- **Saved** — you clicked Save from the feed. You haven't applied yet.
- **Applied** — you've submitted an application. `applied_at` timestamp is set on first transition here.
- **Phone screen** — first call (recruiter or hiring manager) is scheduled or complete.
- **Technical** — technical interview stage (coding, system design, etc.).
- **On-site** — the final round (on-site, virtual on-site, whatever your company calls it).
- **Offer** — offer received. Terminal.
- **Rejected** — they said no. Terminal.
- **Withdrawn** — you said no (or just stopped pursuing). Terminal.

The kanban renders the first five active statuses as full columns, and the three terminal statuses in a collapsed section at the bottom.

## Transitions

You transition an application by:

- **Dragging the card** between kanban columns.
- **Editing on the detail page** — the status dropdown.
- **Withdrawing** — which drops the status straight to `withdrawn`.

V1 doesn't enforce legal transitions — you can jump from Saved to Offer if you want. But every transition writes a `status_change` event, so the timeline is honest about what happened.

## Terminal vs non-terminal

Three statuses are terminal: **offer**, **rejected**, **withdrawn**. What terminal means:

- The application drops out of your **next-action reminder** count (even if `next_action_at` is in the past, terminal apps aren't counted).
- The card moves to the collapsed section of the kanban so it doesn't clutter your active pipeline.
- Nothing is deleted. You can still open the detail page, see all history, and "un-terminate" by dragging back to an active status.

## The append-only event log

Every mutating action on an application writes an `application_events` row. The types are:

- `status_change` — when you move between columns.
- `note` — when you save a non-empty notes change.
- `contact_added` — when you attach a contact.
- `document_attached` — when you upload a document.
- `reminder` — when the daily scan writes one for a due application.
- `interview` — reserved for future use.

The events table is **append-only at the model level**. The Eloquent `ApplicationEvent::booted()` hook rejects `UPDATE` queries with a LogicException. To "correct" an event, you do the compensating action — and both events appear in the timeline.

Why append-only? Application pursuit happens over weeks, often months. People forget what they did. Having an unalterable log of "what happened when" is genuinely useful during a debrief ("why didn't I get to the on-site?") and prevents the temptation to rewrite history.

## The save-time snapshot

When you save an application from the feed, Throughline copies the **current stage-2 rationale** (fit score, rationale, gaps, narrative, stage-1 score, timestamp) into `applications.stage2_snapshot`. This is deliberate.

**Why snapshot?** Because reranks happen on a schedule. If you save a role today with a fit score of 85, and next week a nightly rerank (after a resume update) changes the live fit score to 72, you want to remember what you saw when you saved. The snapshot preserves that.

You see the snapshot on the application detail page under "Rationale at save-time".

## One application per (user, job)

There's a unique constraint on `(user_id, canonical_job_id)`. You can't save the same job twice under the same account — a second save attempt returns 409 with a pointer to the existing application.

This is why a job that appears in two of your search profiles' feeds doesn't produce two applications when you save.

## What you can't do

- **Delete an application.** V1 doesn't support hard delete. Withdraw instead.
- **Edit an event.** Append-only.
- **Assign an application to a different job.** If you saved the wrong job, withdraw it and save the right one.

## Related

- [Tracking an application end-to-end](/help/tutorials/tracking-an-application-end-to-end)
- [Withdraw an application](/help/how-to/withdraw-an-application)
