---
title: Change your search criteria
summary: Edit the filters and semantic hints on an existing search profile.
order: 2
tags: [search-profiles, filters]
updated: 2026-04-24
---

You created a profile and now you want to tweak it — loosen the comp floor, add a remote-only requirement, exclude a few more companies. Here's the fastest path.

## Edit a profile

1. Open **Search profiles** in the left nav.
2. Click the name of the profile you want to edit.
3. Change whatever fields you want. See [the fields explained in the setup guide](/help/getting-started/04-creating-your-first-search-profile).
4. Click **Save changes**.

## What recomputes

Changing certain fields triggers an automatic recomputation of your `job_matches` rows:

- **Changing the linked resume** — full rerun of stage-1 fan-out against the new resume. Your old matches are replaced.
- **Changing any hard filter** (remote mode, comp minimum, excluded companies) — rows that violate the new filter are deleted; rows that newly qualify are inserted.
- **Changing semantic hints** (role keywords, industries, seniority levels) — no immediate recomputation. The hints take effect on the **next stage-2 rerank**, which happens on feed view if the top-K is stale, or in the next nightly refresh.

## Pausing a profile

Uncheck **Is active** and save. The profile is preserved but stops producing new matches. Useful when you're not actively looking but want to keep the configuration for later.

Re-check **Is active** when you want it running again.

## Duplicating a profile

V1 doesn't have a one-click duplicate. Workaround: open the profile you want to copy, mentally note the values, click **New profile**, fill in the same values with tweaks. Two minutes.

## Deleting a profile

From the edit form, **Delete profile**. This cascades to any `job_matches` rows for this profile — they're removed. Applications you've already saved stay (the `search_profile_id` on the application is nullable).

## Common patterns

- **"Loosen the net"** — you're getting too few matches. Clear the comp minimum, change remote mode to `any`, remove any over-specific seniority levels. Save. Feed should repopulate.
- **"Tighten the net"** — too many irrelevant matches. Add excluded companies you never want to see again. Raise the comp floor. Keep the remote mode at what you actually want.
- **"Shift the focus"** — change the linked resume. Mostly useful when you realize your search-profile + resume combo is producing rationales that don't reflect what you actually want.
