---
title: Not receiving emails
summary: Reminder emails aren't arriving — here's how to figure out why.
order: 3
tags: [email, reminders, troubleshooting]
updated: 2026-04-24
---

Throughline sends two kinds of email: authentication-related (password resets, email verification) and reminder emails when applications are due. If you're not getting them, one of a handful of things is going on.

## Quick checklist

1. **Check your spam folder.** Especially for first emails from a new install.
2. **Confirm your email address on file** is the one you're checking. Profile → Settings shows what Throughline has on record.
3. **Check your notification preferences.** Admins (or self-hosters via tinker) can toggle `notification_email_enabled`. If it's off, you get zero emails.
4. **Check timing.** Reminder emails fire once per day at 09:00 UTC by default. If it's currently 08:00 UTC and no applications were due yesterday, no email will send.
5. **Check you have applications due.** Reminders only fire for apps with `next_action_at` in the past or today. If nothing's due, nothing sends.

## Case 1: first email ever, and it's missing

New installs and new users have the highest chance of mail delivery issues. Providers treat a sender they've never seen skeptically.

**What to try:**

- Check spam/junk/promotions folders, including in Gmail's secondary tabs.
- Add `no-reply@<your-throughline-domain>` (or whatever the install's `MAIL_FROM_ADDRESS` is) to your contacts.
- Ask the operator whether SPF/DKIM are configured for the sending domain. Without these, Gmail and Outlook often silently route Throughline email to spam.

## Case 2: used to get emails, suddenly stopped

Delivery reliability sometimes degrades when a sender domain gets marked as suspicious (usually from another service's traffic on the same domain).

**What to try:**

- Check spam again.
- Check whether other people on the same install are still getting emails. If everyone's missing them, it's a server/delivery issue.
- Ask the operator to check the mail queue (`failed_jobs` table or Postmark/SES/whatever's in use) for bounces specifically against your address.

## Case 3: notifications are disabled on your account

Each user has a `notification_email_enabled` boolean. It defaults to true but can be toggled off — useful if you prefer to use just the in-app badge.

**How to check:**

- V1 doesn't expose this as a user-facing toggle. Admins can see it on your account in the admin Users page.
- If you're self-hosting, you can check and set it via `tinker`:

  ```php
  $u = User::where('email', 'you@example.com')->first();
  $u->notification_email_enabled;  // bool
  $u->notification_email_enabled = true;
  $u->save();
  ```

## Case 4: reminder emails only — password reset works fine

If you're getting password resets but not reminder emails, the mail system works and your address is verified. The problem is specifically with the reminders pipeline.

**Likely causes:**

- No applications are due. Reminders only fire when `next_action_at` is today or earlier.
- The nightly reminder scan isn't running (operator-side — see below).
- Your notification_email_enabled is off.

**What to try:**

- Pick an application, set its next action to yesterday, wait for the next 09:00 UTC cycle.
- If it still doesn't send, escalate to the operator.

## Case 5: got a duplicate reminder email

There's a Redis-based dedupe key per user per day. You shouldn't get duplicates in normal operation.

If you do, it's usually because:

- The reminder job ran, crashed mid-flight, and re-ran after the dedupe expired.
- An admin manually re-ran the reminder scan (`php artisan reminders:scan`).

No action needed; one stray duplicate is harmless.

## Case 6: password reset emails aren't arriving

Password reset uses a different template but the same mail pipeline. If you can't receive password resets:

- Check spam.
- Ask the operator to confirm your `email_verified_at` is set — some flows skip sending to unverified addresses.
- Ask the operator to manually dispatch a password reset and watch the log.

## If you're the operator

The reminders pipeline has three distinct parts:

1. **Scheduler** — `app/Console/Kernel.php` schedules `reminders:scan` daily. Confirm with `php artisan schedule:list`.
2. **Scan job** — reads all applications with `next_action_at <= today` and either writes an in-app reminder event, queues an email, or both.
3. **Mailer** — sends the email via your configured `MAIL_MAILER`.

Common failure modes:

- Scheduler not running (missed cron on the host).
- `MAIL_MAILER=log` leftover from dev — emails are written to `storage/logs/laravel.log` instead of sent.
- SMTP credentials expired or throttled.
- SPF/DKIM not configured so everything goes to spam.

Check `storage/logs/laravel.log` around 09:00 UTC for `ReminderScan` output.

## Related

- [Using reminders effectively](/help/tutorials/using-reminders-effectively)
- [Opt out of email reminders](/help/how-to/opt-out-of-email-reminders)
