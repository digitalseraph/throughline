---
title: How matching works
summary: The two-stage approach Throughline uses to rank jobs — cosine similarity for breadth, Claude for judgment.
order: 1
tags: [matching, concepts]
updated: 2026-04-24
---

Throughline's matching is two-stage. Stage-1 is a cheap vector similarity for every job in the pool. Stage-2 is a careful Claude read of the top candidates. Understanding both helps you read your feed well.

## Stage-1: cosine similarity

Every resume and every job description gets an **embedding** — a 1,536-dimensional vector produced by OpenAI's `text-embedding-3-small` model. The embedding is a compressed semantic representation: roles about "distributed systems" cluster near each other in vector space, regardless of which specific tech stack words they use.

When a new resume or new job lands, Throughline computes **cosine similarity** (`1 - (a <=> b)`) against every relevant counterpart. A score of 1.0 means the vectors are identical; 0.0 means orthogonal (totally unrelated). In practice, real matches range 0.4–0.8.

Stage-1 is **fast and cheap**. A stage-1 run for a new job against all your active profiles costs microseconds of CPU and zero API calls (the embeddings are already computed).

Stage-1 is also **shallow**. Cosine similarity reflects topical closeness — two roles about the same kind of work will rank high. It doesn't reason about career fit, seniority alignment, or specific requirements.

### The stage-1 score floor

There's a configurable floor (`stage1_score_floor`, default `0.2`). Jobs whose similarity to your resume is below the floor don't even produce a match row in your database. This keeps obviously-irrelevant roles out of your feed entirely.

### Hard filters at stage-1

Before cosine similarity is even computed, Throughline applies your search profile's hard filters:

- **Remote mode** — only jobs matching your preference make it to the similarity check.
- **Comp minimum** — jobs below your comp floor are excluded (unless they have null comp data, in which case they pass through).
- **Excluded companies** — jobs whose `company_name` matches any of your excludes are skipped.

## Stage-2: Claude rerank

The top-K stage-1 candidates (default 20) get sent to Claude Sonnet in a single batch. The prompt:

> You rerank job postings against a candidate resume. Return structured JSON with one entry per input job, each containing fit_score (0–100), rationale (2–4 sentences), gaps (array of {requirement, severity}), and narrative (one sentence).

Claude reads your resume once per batch (it's in the system context) and then produces structured output for each job. The **fit score** becomes the primary sort field; **narrative + rationale + gaps** are what you read on the card.

Stage-2 is **slower and costlier** than stage-1, but qualitatively different: Claude reasons about alignment, not just surface similarity. A role topically similar to your resume might still be a bad fit (wrong seniority level, mismatched industry focus), and Claude will flag it.

### When stage-2 runs

- **On feed view**: if the top-K rows are stale (`stage2_at` null, or older than `stage2_ttl_hours` — default 24h), a synchronous rerank fires. Budget is ~3 seconds.
- **Nightly**, at a configurable UTC hour (default 04:00): a job refreshes the top-50 per active profile. Morning feeds are fast.
- **Not per-job-ingestion**. Newly-ingested jobs get stage-1 match rows immediately but don't trigger stage-2 for anyone automatically. Stage-2 happens in batches, at view time or nightly.

### Why "all or throw"

If Claude returns output that's missing any required field, or omits any of the input jobs, `AiClient::rerank` throws `RerankFailed`. We don't show partial rerank results.

When this happens, the system writes a sentinel value (`stage2_rationale = 'rerank unavailable'`) to the affected match rows. The next rerank cycle retries. You see "AI rationale temporarily unavailable" on those cards briefly.

## The join

Both stages write to the same `job_matches` row per (profile, job) pair:

- Stage-1 writes `stage1_score`.
- Stage-2 writes `stage2_score`, `stage2_rationale`, `stage2_gaps`, `stage2_narrative`, `stage2_at`.

The feed orders by `stage2_score DESC NULLS LAST, stage1_score DESC`. Fresh-reranked matches rise above never-reranked ones; among rows with the same rerank state, stage-1 breaks ties.

## What this means for you

- **Breadth** comes from stage-1 — any remotely relevant job will produce a match row.
- **Ranking quality** comes from stage-2 — the ordering of your top-K reflects Claude's judgment.
- **Freshness** is managed by the TTL — within 24 hours of any rerank, the top cards are current.
- **Cost** is bounded — Claude only processes a small top-K, not every job you've ever seen.

## Related

- [What is fit score](/help/concepts/what-is-fit-score)
- [Understanding stage-2 rationale](/help/concepts/understanding-stage-2-rationale)
- [Understanding the feed](/help/tutorials/understanding-the-feed)
