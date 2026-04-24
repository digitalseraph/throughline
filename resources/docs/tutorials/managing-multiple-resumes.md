---
title: Managing multiple resumes
summary: How to use multiple resume variants to target different career arcs at the same time.
order: 1
tags: [resumes, tutorial]
updated: 2026-04-24
---

If you're exploring more than one direction in your next role — say, both "staff engineer at a larger company" and "engineering manager at a series B" — you want different resumes for each. Throughline supports as many resume variants per user as you want, and each search profile points at a specific variant.

## Why multiple resumes matter

The resume Throughline embeds is the basis for every stage-1 cosine similarity calculation **and** the context Claude uses during stage-2 rerank. If your resume emphasizes platform engineering, your feed and your Claude rationales lean platform engineering. If it emphasizes team leadership, they lean management.

**Using two resumes gives you two distinct feeds.** Each shows you the best matches through its own lens.

## A concrete example

Let's say you're a Staff Engineer who's been hands-on for the last 5 years but is considering a management pivot. You have two realistic paths:

1. Continue as a principal/staff IC at a company that values deep technical ownership.
2. Transition into engineering management.

Each path has a different ideal resume:

- **IC-track resume** — leads with technical depth. Architecture decisions, system design work, critical incidents led. "Shipped X at scale", "Reduced Y latency by Z%".
- **Manager-track resume** — leads with people and impact at team scale. Hires made, reviews written, processes introduced. "Grew team from 3 to 8", "Shepherded N shipped projects as hiring manager".

## Set it up

1. Upload **both** resumes. Name them so you'll recognize them — "Staff IC track" and "Engineering manager track" are fine.
2. Create **two search profiles**:
   - "Staff/principal IC search" — linked to your IC resume. Role keywords: `staff, principal, staff engineer, principal engineer, architecture`. Seniority levels: `staff, principal`. Industries: whatever you care about.
   - "Engineering manager search" — linked to your manager resume. Role keywords: `engineering manager, team lead, manager`. Seniority levels: `manager, senior manager, director`.
3. Each profile gets its own feed. Both draw from the same pool of ingested jobs, but the ranking, fit scores, and narratives diverge because the embedding context is different.

## What you'll notice

- The **same job** can appear in both feeds with **different fit scores** and **different narratives**.
- Stage-2 rerank for the IC-track profile frames things like "builds on your platform ownership history". The manager-track rerank frames the same role differently — focusing on whether it's an IC role (poor fit) or includes management (good fit).
- **Gap chips differ** too. An IC role might be a "stretch" for your manager-track profile (you'd lose people-management leverage); the same role might be "blocker-free" on your IC track.

This comparison is genuinely useful. You can read the same role through two lenses and decide which direction it serves.

## Setting a default

One of your resumes is marked as the **default** — the first one you uploaded is default by default (sorry). This is mostly cosmetic; default-ness doesn't affect matching.

To change the default, open a resume's detail page and click **Set as default**.

The default is useful later when you save a job from the feed — Throughline records which resume was linked to the profile at save-time, snapshotted into the application. If you had different defaults for different profiles, you can recreate which resume was active at save-time by reading the snapshot.

## When one resume updates

If you edit your IC-track resume to include a new project, re-upload it. Here's what happens:

1. The new upload replaces the old (or creates a new variant if you uploaded with a different name).
2. The parse + embed pipeline runs fresh.
3. Every **search profile linked to this resume** has its stage-1 matches recomputed — the `job_matches` rows are regenerated.
4. Stage-2 data on the regenerated rows is blank again, so the next feed view triggers a fresh rerank.

The feed you see the next morning reflects your updated resume. Good.

## When to delete a resume

Delete a resume when you're confident you won't want its historical context again. Remember:

- Deleting a resume cascades to search profiles that linked it — they have their `resume_id` set to null (they stay, just stop producing matches until you link another resume).
- **Applications** you saved with that resume keep the `stage2_snapshot` — the Claude rationale as of save-time is preserved.

If you're unsure, keep it. Resumes don't cost anything to store.

## Common pitfalls

- **One resume for two different arcs.** If you try to cover both IC and manager paths with one resume, the embedding ends up in no-man's land — neither signal dominates, and the stage-1 scores are mediocre across the board. Split them.
- **Too many variants.** If you have 7 resumes for 7 subtly different pitches, you're probably over-indexing on variant crafting vs. looking at roles. Two or three is enough for most people.
- **Forgetting to update.** A resume you uploaded 3 months ago doesn't know about your last project. Re-upload when meaningful things change.

## Related

- [Creating a search profile](/help/getting-started/04-creating-your-first-search-profile)
- [How matching works](/help/concepts/how-matching-works)
- [Change your search criteria](/help/how-to/change-your-search-criteria)
