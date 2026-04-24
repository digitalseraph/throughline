---
title: Creating your account
summary: Three ways to sign in, what happens to your data, and what the "first user is admin" rule means.
order: 2
tags: [signup, auth, oauth]
updated: 2026-04-24
---

You can create a Throughline account in three ways. Pick whichever is convenient — they all land you in the same place.

## Option 1: Email and password

1. Click **Get started** (or **Register** if you're on the login page).
2. Fill in your name, email, and a password. The password field needs a confirmation — pick something you can remember.
3. Click **Create account**. You're logged in.

## Option 2: Continue with Google

1. On the login or register page, click **Continue with Google**.
2. Google's consent dialog opens. Confirm the permissions (email + profile).
3. You return to Throughline, logged in. If it's your first visit, an account was created automatically.

The name on your account is whatever Google has on file for you. You can change it later.

## Option 3: Continue with GitHub

Same flow as Google, via GitHub. The scopes are minimal — we read your email and name, nothing else.

## Linking multiple sign-in methods

If you start with email + password and later decide you'd rather use Google, you can. Sign out, then click **Continue with Google** on the login page — if the Google email matches your account, we link them together. Next time, either path signs you in to the same account.

The same works for GitHub. You can have all three (password + Google + GitHub) linked if you want redundancy.

## What we store

When you create an account, we save:

- **Name, email, password** (password is hashed via bcrypt — we never see your plaintext).
- **OAuth tokens** for Google or GitHub if you used those — stored encrypted at rest.
- **Last login timestamp** — used to show "N-day active" in admin views.

When you upload resumes, create search profiles, save jobs, add contacts — all of that lives in the database tied to your user id. You own it. We built the application with data portability in mind; export workflows will ship in a future update.

## What if I lose access?

- **Forgot password:** Use the "Forgot password" link on login. A reset email is sent.
- **Lost the OAuth account:** If you only linked one OAuth provider and can't access that account, contact support (if you're on a hosted install) or an admin (if you're self-hosted).

## First user becomes admin

On a fresh install of Throughline, the **first person to register** is automatically promoted to `role = admin`. That's you if you're self-hosting and just spun up the app. Admins have access to the `/admin` section for managing users, tuning settings, viewing analytics, impersonating users for support, and so on.

If you're not the first user and you need admin access later, ask an existing admin to promote you via the admin Users page.

## Next

- [Upload your first resume](/help/getting-started/03-uploading-your-first-resume)
