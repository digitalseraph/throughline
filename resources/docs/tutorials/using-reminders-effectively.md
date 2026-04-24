---
title: Using reminders effectively
summary: Set good next-action dates, understand when emails fire, and turn the reminder system into a quiet checklist instead of another notification stream.
order: 3
tags: [reminders, tutorial, applications]
updated: 2026-04-24
---

The reminder system exists for one reason: to make sure you never lose a lead because you forgot to follow up. Applied two weeks ago, haven't heard back, want to send a polite check-in — that's exactly when you need a nudge.

But reminder systems have a failure mode: they become yet another inbox. Here's how to keep Throughline's reminders useful.

## The model

Every application has one `next_action_at` field. It's either:

- **Null** — no pending action. The application is "quiet". No reminder fires.
- **A future date** — you've committed to doing something on or after that date. No reminder fires until the date arrives.
- **A past date (today or earlier)** — the action is due. Reminders fire.

Reminders are silent until the date arrives. They're not notifications *on* the date — they're the date *becoming* a past date.

## When reminders fire

Daily at 09:00 UTC (configurable per-install), `ApplicationReminderScan` runs. For each non-terminal application with `next_action_at <= now()`:

1. A `reminder` event is appended to the application's timeline — one per day max, regardless of re-runs.
2. The nav badge count updates live — you see a red pill on **Applications** when you have any due.
3. If you have `notification_email_enabled`, a reminder email is queued and sent. One per day max.

So you get at most **one nav badge update and one email per day**, summarizing everything that's due.

## Setting good next-action dates

The trick is to pick dates that mean something. Vague "check back eventually" reminders rot in your list.

Concrete patterns:

- **Applied and waiting**. Set `next_action_at` to 7–10 days out. If you haven't heard back by then, that's your cue to follow up.
- **Phone screen scheduled for Friday**. Set to Saturday — the day after. You'll want to write a thank-you note and mark how it went.
- **Promised to send a follow-up by Tuesday**. Set to Tuesday. Don't let it slide.
- **Waiting on a decision after on-site**. Set to 7 days past the on-site. If still silent, write a polite nudge.
- **Declined for reasons**. Set to 3 months out if you want to re-engage with that company later. Or leave null if it was a hard no.

Don't set reminders for everything. A role you applied to and then moved to "Rejected" doesn't need a reminder — it's terminal.

## Clearing a reminder

When you've done the action (sent the follow-up email, wrote the thank-you, checked in with the recruiter), **clear or update `next_action_at`**.

Two options:

1. **Clear the field** on the application detail page. Marks the application as "quiet" again.
2. **Set it to a future date** for the next natural checkpoint.

Once the field is not-in-the-past, the application drops out of your badge count.

## The badge count

The nav badge shows your total count of due applications across all profiles, all statuses (except terminal). It's cached for 60 seconds in Redis, so a refresh may lag slightly. The WebSocket push path (when the reminder scan fires or when you edit a date) bypasses the cache.

The count is a good daily prompt. Open the app, see "Applications: 3", go deal with the 3. The job of the system is to make this a small, finite number — not a growing backlog.

## Email reminders

If opted in, you get a **daily digest email** on days when at least one application is due. Subject: "Applications due for follow-up". Body: a list with links back to the detail pages.

The email goes out once per day per user — if the scan runs twice (queue retry after a failure), a Redis cache key (`reminder_email_sent:{user}:{date}`) prevents the second send.

## Opting out of emails

If you prefer the in-app badge and not the email:

- For now (v1), there's no user-facing toggle. A self-hosted admin can flip `users.notification_email_enabled` to `false` for your account. (A future version will expose this in a profile settings page.)

## Practical cadence

A suggested rhythm:

1. **Daily (morning)**: Open Applications. Work through anything in the badge count. Set new `next_action_at` values for applications that moved forward.
2. **Weekly (Monday)**: Scan your Saved column. Anything you've been sitting on for more than a week? Either apply or move it to Withdrawn.
3. **Monthly**: Review terminal applications. Any patterns in your rejections? Anything worth re-engaging in 3 months?

Reminders handle the first bullet. Manual review handles the rest.

## Related

- [Opt out of email reminders](/help/how-to/opt-out-of-email-reminders)
- [How the application pipeline works](/help/concepts/how-the-application-pipeline-works)
- [Tracking an application end-to-end](/help/tutorials/tracking-an-application-end-to-end)
