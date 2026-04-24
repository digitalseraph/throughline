---
title: Opt out of email reminders
summary: Keep the in-app badge, skip the daily digest.
order: 7
tags: [reminders, email]
updated: 2026-04-24
---

If you like seeing the nav badge on **Applications** but don't want the daily summary email, you can opt out.

## V1 status

There isn't a user-facing toggle in the UI yet. You have two options:

### Option A: ask an admin

On self-hosted installs, an admin can go to `/admin/users`, open your user, and — via tinker or a future UI control — set `notification_email_enabled` to `false`.

### Option B: use tinker yourself (if you have shell access)

```bash
php artisan tinker
>>> User::where('email', 'you@example.com')->update(['notification_email_enabled' => false]);
```

The nav badge continues to update. The emails stop.

## What you still see

- **Nav badge** — the red pill next to "Applications" updates live whenever you have due reminders.
- **In-app reminder events** — the `reminder` event still lands on the application's timeline, regardless of your email setting.
- **The `/applications?filter=due` view** — you still see due apps there.

The only thing that goes away is the daily digest mail.

## Re-opting in

Same path, set `notification_email_enabled` back to `true`. The next scan that produces due reminders will queue your email.

## Future

A user-facing toggle in the profile settings page is a planned follow-up. For now, the database-level flag is the mechanism.

## Related

- [Using reminders effectively](/help/tutorials/using-reminders-effectively)
