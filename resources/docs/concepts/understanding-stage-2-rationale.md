---
title: Understanding stage-2 rationale
summary: What Claude's rationale, gaps, and narrative are for, and how to read them with appropriate skepticism.
order: 3
tags: [claude, rationale, concepts]
updated: 2026-04-24
---

Every feed card shows three Claude-produced fields: the **rationale**, the **narrative**, and the **gap chips**. Together they're the judgment layer on top of the raw fit score. This page is about how to read them well.

## Narrative

A single sentence. Something like:

> *"Natural next step after your platform tenure at Acme; extends technical ownership without requiring a management pivot."*

The narrative is framing — how you'd describe this move if someone asked *"why did you apply there?"*.

A good narrative makes you nod. If the narrative reads weirdly — *"this role would be a shift into frontend work despite your backend experience"* when you actually want to stay backend — it means your profile's seniority/industry hints aren't aligned with your actual search. Either fix the profile or read the role with the narrative's framing and decide if it still appeals.

## Rationale

Two to four sentences explaining the fit. The rationale does three things:

1. **Confirms the match** — why Claude thinks this is a yes or a maybe.
2. **Surfaces key alignments** — specific things on your resume that match specific things in the job.
3. **Provides cover for gaps** — often acknowledges what's missing and why it's still a reasonable apply.

Example:

> *"Your 6 years at distributed-systems-heavy companies line up well with Acme's platform team goals. The posting emphasizes Go and Rust; your Go experience is strong, and while Rust is newer for you, the team's 'willing to teach' language suggests they'll invest in ramp-up. Your staff-level ownership pattern at Foo fits the scope Acme describes."*

Read the rationale after glancing at the fit score. The score tells you "worth reading?"; the rationale tells you "why?".

## Gaps

An array of structured entries. Each has a `requirement` (what the job wants) and a `severity`:

- **Blocker** (red chip) — a hard requirement you don't meet. Take seriously.
- **Stretch** (muted chip) — a nice-to-have you partially cover. Often not dealbreaking.
- **Minor** (outlined chip) — small gaps that usually don't matter.

Reading gaps well:

- **One blocker, no stretches** — usually a real stop. If the blocker is "PhD required" and you don't have one, you're probably not applying.
- **Two or three stretches, no blockers** — normal. Every job has these. Apply anyway if the rationale and narrative are good.
- **One blocker framed softly** — sometimes Claude flags a blocker that's more nuanced ("5+ years React; you have 3"). Read the context. If your 3 years are recent and heavy, a company's "5+" is often flexible.

Claude is **deliberately honest** about gaps. We didn't want a match tool that flatters you into applying to everything. If a role is a stretch, you'll see it. If it's genuinely off, you'll see that too.

## When Claude is wrong

It happens. Failure modes:

- **Missed something in your resume.** Gap chip says "no Kubernetes experience" but your resume lists a Kubernetes project. Claude skimmed — usually because the mention was brief. Fix: beef up the resume for that skill or accept the chip is wrong.
- **Overindexed on a single phrase.** A job description mentions "distributed systems" once, and Claude calls it a great fit because your resume does too, but the rest of the job is about something else entirely. Rare but happens.
- **Hallucinated a gap.** Claude occasionally invents a requirement that isn't in the actual job description. Uncommon with modern Sonnet; more common in prompts that are too long.

If a rationale or gap feels off, you can read the original posting (the **View posting →** link). Claude is useful but not infallible.

## When the feed shows no rationale

Two states:

- **AI rationale pending** — Claude hasn't been asked to rerank this row yet. Refresh; if the top-K is stale, a rerank fires on view.
- **AI rationale temporarily unavailable** — Claude returned unparseable output for this card's batch, and a sentinel value was written to prevent tight retry loops. The next rerank cycle (next feed view or nightly) will retry.

## Why structured, not free-form

The rationale could have been a single blob of text. We deliberately separated **narrative** (one-line framing), **rationale** (2–4 sentences), and **gaps** (structured entries) because they play different roles in decision-making:

- **Narrative** is triage — does this match my career shape?
- **Rationale** is consideration — why is this a match?
- **Gaps** are dealbreaker checks — what's missing?

Structured output lets the card render each component with its own visual weight. It also means we can improve each independently (swap the narrative prompt without changing rationale behavior).

## Related

- [How matching works](/help/concepts/how-matching-works)
- [What is fit score](/help/concepts/what-is-fit-score)
- [Understanding the feed](/help/tutorials/understanding-the-feed)
