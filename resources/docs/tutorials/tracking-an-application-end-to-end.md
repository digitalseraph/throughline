---
title: Tracking an application end-to-end
summary: From Save in the feed, through the kanban, to offer or rejection — a guided walkthrough of the full application lifecycle.
order: 2
tags: [applications, kanban, tutorial]
updated: 2026-04-24
---

Most job-tracking tools are either way too minimal ("a big list of rows") or way too maximal ("a CRM for your career"). Throughline sits in the middle: the things you actually do for each role get first-class surfaces, nothing else.

This tutorial walks through tracking a single application from the moment you see it in the feed to the moment it closes.

## Step 1: Save from the feed

You're reading your feed. A cardcatches your eye — Senior Backend Engineer at Acme, 82 fit score, narrative reads *"extends your data-infrastructure ownership from Stripe era without management responsibility."* The blocker gap chip says *"no Rust experience"* — fair, but a stretch you're willing to take.

Click **Save**.

Throughline does the following atomically:

- Creates a new application in "Saved" status.
- **Snapshots the rationale and gap data** into `stage2_snapshot` on the application. This is yours forever — if you update your resume next week and the rerank changes the live fit score for this job, your saved context preserves what you saw.
- Writes the first `application_events` row — a `status_change` event marking "null → saved".

The card on the feed re-renders with a "Saved" indicator.

## Step 2: Open the application detail

Navigate to **Applications** in the left nav. Your new application is in the Saved column of the kanban.

Click the card. You land on the application detail page, which shows:

- **Title and company** at the top, with the apply URL.
- **Rationale at save-time** — preserved from the moment you clicked Save.
- **Notes** — an empty textarea to start.
- **Timeline** — one entry so far: "Status: — → saved".
- **Withdraw** link at the bottom.

## Step 3: Apply

You fill out Acme's application form (externally — Throughline doesn't auto-apply). Come back, drag the card from **Saved** to **Applied**. Throughline:

- Updates `status = 'applied'` and sets `applied_at = now()`.
- Writes a `status_change` event: "saved → applied".
- Fires a real-time event so any other tab you have open updates live.

The timeline now shows two entries.

## Step 4: Add a contact and a document

A few days later, Acme emails you with phone screen details. You want to keep the recruiter's info on the application.

On the detail page, in the Contacts section:

- **Name**: Alex Recruiter
- **Role**: Recruiter
- **Email**: alex@acme.com
- **Notes**: "Responded same-day; mentioned hybrid flexibility"

Save. A `contact_added` event appears in the timeline.

While you're there, attach your cover letter as a document:

- **Kind**: Cover letter
- **File**: `acme-cover.pdf`

A `document_attached` event appears. Future-you will thank you for keeping these together.

## Step 5: Set a next action

The phone screen is scheduled for Friday. Set `next_action_at` on the detail page to Friday's date. This does two things:

1. The application appears in your **Applications → filter by due** view when the date arrives.
2. At 9:00 UTC on Friday (or whenever `reminder_scan_hour_utc` is configured), Throughline writes a reminder event and, if you have email reminders enabled, sends you a mail.

## Step 6: Transition through the pipeline

Phone screen goes well. Drag the card to **Phone screen**. Status change event, timeline entry.

A week later, technical interview scheduled. Drag to **Technical**, update `next_action_at` to the interview date. Add the interviewer's name as a contact.

Make it through the on-site? Drag to **On-site**. Set a reminder for the following week to follow up on status.

## Step 7: Outcome

One of three things happens eventually:

- **Offer** — drag the card to **Offer**. Add notes with the comp details. The card moves to the collapsed "Terminal statuses" section.
- **Rejected** — drag to **Rejected**. Add notes on the feedback (if you got any). Useful pattern-matching for the next round.
- **Withdrawn** — you withdrew voluntarily (accepted elsewhere, decided not to pursue). Click **Withdraw application** on the detail page (or drag to Withdrawn column). The event log records this as a status change, and the card moves to the collapsed terminal section.

## What you have at the end

An application record with:

- The Claude rationale as it was when you first showed interest.
- A full timeline of every status change, note, contact, document, and reminder.
- A set of contacts tied to this application.
- Cover letter and any other documents attached.
- Your notes, freely edited.

Nothing is lost. If you ever want to debrief — "why did I apply here in the first place?" — the answer is preserved. If you want to reuse the cover letter for a similar role, it's still there.

## The event log is append-only

One detail worth understanding: the timeline is **append-only**. You can't edit or delete an entry. If you want to "correct" something, do the compensating action — drag back to a prior status, write a clarifying note. Both events appear in the log; history is preserved.

This is deliberate. Application pursuit takes weeks; you'll forget details. The append-only log is honest about what happened.

## Related

- [Add a recruiter contact](/help/how-to/add-a-recruiter-contact)
- [Attach a cover letter](/help/how-to/attach-a-cover-letter)
- [Withdraw an application](/help/how-to/withdraw-an-application)
- [Using reminders effectively](/help/tutorials/using-reminders-effectively)
