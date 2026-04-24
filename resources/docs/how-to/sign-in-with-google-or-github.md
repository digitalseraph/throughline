---
title: Sign in with Google or GitHub
summary: Use OAuth instead of email and password.
order: 1
tags: [auth, oauth, signup]
updated: 2026-04-24
---

If the **Continue with Google** or **Continue with GitHub** buttons are visible on the login or register page, you can use them:

1. Click the button on `/login` or `/register`.
2. The provider's authorization dialog opens in a new page. Confirm the minimal permissions (email + profile).
3. You return to Throughline, logged in.

If the buttons aren't visible, OAuth hasn't been configured for this install. Email + password signup still works; or ask the admin to add credentials per [the external accounts setup doc](https://github.com/digitalseraph/throughline/blob/main/docs/external-accounts-setup.md) (admin-only).

## Linking a provider to an existing account

If you already have an email + password account and want to add Google sign-in:

1. Log out.
2. Click **Continue with Google** on the login page.
3. If the Google account's email matches your Throughline email, we link them automatically. Next time, either path signs you in to the same account.

## Multiple providers on one account

You can link Google **and** GitHub **and** keep email + password. All three point at the same account.

The `social_accounts` table stores one row per linked provider identity. If you later revoke the OAuth app from your Google account, sign in with email + password, you're fine.

## What we store

When you sign in with OAuth, we save:

- Your provider account id (so we know it's you next time)
- Your email and display name (to pre-fill your Throughline profile)
- Access and refresh tokens (encrypted, used to re-query profile info if it changes)

We don't request any beyond-baseline scopes — no calendar, no repo access, nothing.

## Related

- [Creating your account](/help/getting-started/02-creating-your-account)
