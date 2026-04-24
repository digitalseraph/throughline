---
title: What is the fit score?
summary: How Throughline's 0–100 score is produced, what it means, and how to read it well.
order: 2
tags: [fit-score, matching, concepts]
updated: 2026-04-24
---

The **fit score** is a number between 0 and 100 that Claude assigns to each job relative to your resume and search profile. It's the primary signal on the feed.

## What the number captures

Claude is prompted to assess:

- **Role alignment** — does this match the kind of work your resume reflects?
- **Seniority alignment** — does the role level make sense for your trajectory?
- **Skill coverage** — how well does your resume cover the technical requirements?
- **Career-arc fit** — is this a natural next step given where you've been?

A single integer can't capture all of this nuance, but it's a useful triage signal. High scores consistently correspond to roles you want to read carefully; low scores consistently correspond to roles you don't.

## What the badge colors mean

The card renders the fit score badge in one of three styles:

- **Oxblood solid** (≥ 80) — strong match. Claude thinks your resume aligns well across the dimensions above.
- **Oxblood tint** (60–79) — respectable. Worth reading the rationale; gaps will tell you where it falls short of a strong match.
- **Muted** (< 60 or null) — weaker. Most people shouldn't prioritize these unless the rationale surprises them.

## Reading the score honestly

A few rules of thumb:

- **A 75 is usually a real match.** Claude doesn't assign 75+ lightly; it means the role is within your arc.
- **An 85 is strong; a 95 is rare.** When you see a 90+, the rationale usually reads *"this role is exactly the kind of thing you've been building toward"*. Trust it, then apply the gap chips as a final check.
- **A 50 isn't necessarily a bad role.** It's probably not an ideal match for *your current resume*. Sometimes this means the role is a stretch — appealing but requires coverage you don't have. Read the gap chips to decide.
- **Scores change over time.** As you update your resume or as we tune our prompts, rerankings on the same job will shift. That's why applications snapshot the rationale at save-time.

## What the score is not

- **It's not a probability of getting hired.** That depends on the company, the rest of their pipeline, negotiation, luck — things Throughline can't see.
- **It's not a match-percentage across skills.** Claude weighs signals qualitatively, not mechanically. A role can be 85-fit with substantial gaps because the gaps are the stretch-y kind that don't preclude an offer.
- **It's not a comparison across users.** Your 82 and someone else's 82 are calibrated against different resumes — they mean different things.

## When the score disagrees with your gut

Three things might be happening:

1. **Claude misread your resume.** If a role's gap chip says "no experience with X" and your resume clearly shows X, Claude slipped — possibly the term was used differently in your resume. The score reflects the misread.
2. **Your resume is under-selling you.** If you've done something substantial but your resume summarizes it in a line, the embedding and Claude both miss it. This is a signal to beef up the resume.
3. **The role is genuinely a reach.** Sometimes your gut says "I could do this" but the on-paper evidence isn't there. Claude calls it honestly. Either rewrite your resume to reflect transferable experience, or accept that you'll apply as a stretch.

## When the score agrees more than your gut

Claude is pretty good at surfacing roles you didn't know to look for. A company you hadn't heard of, a team structure you wouldn't have queried for — and the score is 86. Read it.

## Stage-1 vs stage-2 — quick note

The fit score is stage-2 only. Stage-1 produces a `stage1_score` (a cosine similarity in 0–1), but that's not shown to users — it's an internal ordering signal for what goes into the stage-2 batch.

If a card has no stage-2 fit score yet, you see the "AI rationale pending" or "temporarily unavailable" state. A fresh rerank fires on your next feed view.

## Related

- [How matching works](/help/concepts/how-matching-works)
- [Understanding stage-2 rationale](/help/concepts/understanding-stage-2-rationale)
