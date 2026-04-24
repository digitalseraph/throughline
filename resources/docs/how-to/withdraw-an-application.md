---
title: Withdraw an application
summary: Mark an application as withdrawn without deleting its history.
order: 6
tags: [applications, kanban]
updated: 2026-04-24
---

Sometimes you accept another offer. Sometimes you realize the role isn't for you partway through. Sometimes the company went silent and you've decided to move on. Any of these → withdraw the application.

## Withdraw

Two paths:

1. **From the detail page**: click **Withdraw application** at the bottom. Confirm.
2. **From the kanban**: drag the card into the **Withdrawn** column.

Either way, Throughline:

- Updates the application's status to `withdrawn`.
- Writes a `status_change` event (previous status → withdrawn).
- Moves the card into the terminal statuses section (collapsed by default).

## What withdraw doesn't do

It doesn't delete the application. All your notes, contacts, documents, and event timeline are preserved. You can open the detail page anytime and see everything.

This is deliberate. If you later want to remember — "what happened with that Acme application?" — the history is there.

## Un-withdrawing

If you withdrew prematurely and want to revive the application, drag the card from Withdrawn back to whatever status it should be in (usually Applied or Phone screen). Status-change events are append-only, so the timeline shows both: "Applied → Withdrawn" and later "Withdrawn → Applied". The company doesn't see any of this; it's only your internal tracking.

## Withdrawn vs Rejected

Both are terminal. The semantic difference:

- **Rejected** — the company said no. Drag here when you receive a rejection.
- **Withdrawn** — you said no (or just stopped pursuing). Drag here when you're the one who pulled out.

If you accepted an offer elsewhere, use **Withdrawn** for the applications you're abandoning in favor of the accepted one.

## Related

- [Tracking an application end-to-end](/help/tutorials/tracking-an-application-end-to-end)
