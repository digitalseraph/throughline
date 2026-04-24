---
title: Understanding the feed
summary: A deeper read on how the feed is ordered, why the same job can show differently in two profiles, and how to read Claude's gap chips.
order: 4
tags: [feed, matching, tutorial]
updated: 2026-04-24
---

You've seen your feed. You've saved a few roles. This tutorial goes deeper — the why behind the ranking, what to do when scores are confusing, and how to read Claude's output with appropriate skepticism.

## How the ranking is built

Every card on your feed has two scores:

- **Stage-1 cosine similarity** (0.00–1.00) — how close the resume embedding is to the job embedding in vector space. Fast, cheap, mostly captures topical similarity.
- **Stage-2 fit score** (0–100) — Claude's structured judgment. Reads your resume, reads the job description, returns a number plus rationale plus gaps plus narrative.

The **default sort** is by stage-2 fit score (descending, with nulls at the bottom), then by stage-1 score as tiebreaker. Rows that haven't been reranked yet fall below rows that have.

In practice, on a mature feed:

- The top ~20 are Claude-reranked. Their order reflects Claude's judgment of fit.
- Rows past position 20 may have stale or no stage-2 data. They're shown in stage-1 order — useful breadth but less nuanced ranking.

## Why the same job scores differently across profiles

You might have a "Staff IC search" profile and an "Engineering manager search" profile. Both draw from the same pool of canonical jobs. So why does the same Senior Engineer role show up at fit score 85 in one and 62 in the other?

Because **the resume is different**. Each profile links its own resume, and the embedding + Claude context is derived from that resume. If your IC-track resume emphasizes deep technical work and your manager-track resume emphasizes team leadership, the **same job description looks different through each lens**:

- IC-track: "This role extends your hands-on platform ownership" → high fit.
- Manager-track: "This is an IC role with no team-lead responsibility" → lower fit.

Both are honest. That's the point. You can see which path this role serves.

## Reading gap chips

Gap chips are Claude's way of flagging what's missing or stretch-y. Three severities:

- **Blocker** (red): a hard requirement you don't meet. Read it carefully. If it's something like "5+ years of Kubernetes" and you've never touched Kubernetes, this is usually a real blocker. If it's "PhD in CS" and you have a bachelor's in a related field, a lot of companies are flexible despite what the posting says.
- **Stretch** (muted): a nice-to-have you partially cover. "Experience with Rust" when you've done some Rust side projects but never shipped Rust production. Usually worth applying despite.
- **Minor** (outlined): small gaps Claude flagged but that aren't meaningful for most candidates. "Familiar with Kanban" when your resume mentions Scrum but not Kanban — same skill family, just different label.

**Don't over-trust the chips.** Claude reads text, not context. If a gap chip misrepresents your experience (Claude missed something in your resume), your lived reality wins. The chip is a starting point for self-evaluation, not a verdict.

## What the narrative is for

The one-line narrative subtitle — *"Extends your platform-infra leadership without a management pivot"* — is Claude framing the role against your career arc. It's the sentence you'd use if someone asked "why did you apply there?"

Read it. If it lands ("yes, that's actually how I'd describe this move"), you have a good match. If it lands oddly ("but I'm not trying to stay IC — why is it framed that way?"), dig into the rationale and consider whether your resume + profile are aligned with the search you actually want.

## The `?raw=1` bypass

If you want to see the feed without stage-2 rerank — purely stage-1 order — append `?raw=1` to the URL:

```
/feed/42?raw=1
```

Why would you use this?

- **Debugging.** If stage-2 rerank is misbehaving or Claude is rate-limited, `?raw=1` gives you a clean stage-1 view.
- **Breadth discovery.** Stage-1 surfaces topical matches without Claude's narrative framing, which is sometimes useful when you're exploring.
- **Transparency.** Sometimes you just want to see what the cosine said.

## When there's no rationale ("pending" or "unavailable")

- **AI rationale pending** — the rerank hasn't run yet. Sit tight; the top-K rerank triggers on page view and usually completes within a few seconds.
- **AI rationale temporarily unavailable** — Claude returned unparseable output for this card's batch. The system writes a sentinel and won't retry until the next cycle (protection against tight loops on genuinely-bad prompts). You'll see this briefly; refresh later and it's usually fine.

## How often reranking happens

- **On feed view**, if the top ~20 rows have stale stage-2 (null or older than `stage2_ttl_hours`, default 24h), a synchronous rerank fires. ~3 second wait; the page then reloads.
- **Nightly**, at 04:00 UTC (configurable), every active profile gets its top-50 matches reranked asynchronously. So morning opens are fast.
- **New job ingestion** creates stage-1 match rows; the stage-2 doesn't happen until you view the feed or the nightly refresh hits.

## Practical reading

Read the feed like this:

1. **Scan the top 5–10 cards.** Fit score + narrative should both land for the best roles.
2. **Read the rationale** for any card you're interested in. Does it match your gut sense?
3. **Read the gap chips carefully.** One red blocker is usually a real pause-point. Two reds plus a stretch chip usually means "not for now".
4. **Don't save everything.** The point of the feed is to reduce noise. If you're saving 30 roles a day, either the filters are too loose or the bar is too low.
5. **Trust your taste over the score.** A 72 that feels right beats an 85 that doesn't.

## Related

- [What is fit score](/help/concepts/what-is-fit-score)
- [How matching works](/help/concepts/how-matching-works)
- [Understanding stage-2 rationale](/help/concepts/understanding-stage-2-rationale)
