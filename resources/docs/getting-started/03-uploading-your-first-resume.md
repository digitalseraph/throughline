---
title: Uploading your first resume
summary: What happens when you upload a resume, how long parsing takes, and what "Ready to match" means.
order: 3
tags: [resumes, onboarding]
updated: 2026-04-24
---

Your resume is the foundation for everything else Throughline does — matching, fit scoring, gap analysis all start here. Upload a real one, not a placeholder.

## Steps

1. Open the **Resumes** link in your left nav.
2. Click **Upload resume**.
3. In the dialog:
   - Give your resume a name you'll recognize later ("Staff frontend", "Engineering manager", "Early-career PM"). You can have multiple.
   - Select a PDF or DOCX file. Max 10 MB.
4. Click **Upload**.

## What happens next

Your resume goes through a short pipeline:

1. **Text extraction** — we pull the plain text out of the PDF (using Poppler) or DOCX (using PhpWord). This usually takes a second or two.
2. **Claude parse** — we send the raw text to Claude, which returns structured JSON (your skills, past titles, companies, years of experience). This takes 5–15 seconds.
3. **OpenAI embedding** — Claude's output plus the raw text is embedded with OpenAI's `text-embedding-3-small` model. The resulting vector lives in your resume record and is what matching compares against. 1–5 seconds.

You don't need to wait on the page — Throughline uses WebSockets to push updates to the detail view. You'll see the status move through:

- **Parsing your resume** — Claude is working on it.
- **Parsed. Generating embedding…** — Claude done, OpenAI next.
- **Ready to match against jobs.** — Everything's live. This is the state you want.

## What resumes work best

PDFs with **embedded text** parse cleanly. Any resume you exported from Google Docs, Word, or a LaTeX template fits here.

PDFs that are **scanned images** (a PDF where every page is a picture) won't parse. We don't OCR in v1. If yours is scan-only, export a fresh copy from the original source first.

DOCX files work for straightforward layouts. Unusual table-based layouts sometimes lose structure — but the raw text is what matters for matching, so content still flows through.

## If parsing fails

Sometimes Claude returns JSON that doesn't match our expected shape, or the PDF extractor can't find any text. The status becomes **Parse failed**.

From the detail page you can:

- **Retry parse** — re-queue the pipeline. Often this is enough for transient hiccups.
- **Upload a fresh copy** — sometimes the file itself is the issue.

## Multiple resumes

You'll want more than one if you have more than one career shape you're exploring. Create a separate resume for each — "Manager track" vs "Staff engineer track" vs "Startup founder" — and link each to its own search profile.

The **default** resume is just a convenience — the first one you upload is marked default. Default-ness doesn't affect matching; profiles link to specific resumes by id.

## Next

- [Set up your first search profile](/help/getting-started/04-creating-your-first-search-profile)
