---
title: Resume stuck parsing or "Parse failed"
summary: What to do when your resume never leaves the parsing state — or fails outright.
order: 1
tags: [resume, parsing, troubleshooting]
updated: 2026-04-24
---

A resume that won't finish parsing blocks matching — we can't generate embeddings or run Claude against a resume we couldn't read. Here's how to diagnose and fix the common cases.

## What you'll see

On the resume detail page, the status badge shows one of:

- **Parsing** — the job is running or queued. Usually finishes in 30–90 seconds. If it's been sitting on this for more than 5 minutes, something is wrong.
- **Parse failed** — the job ran and hit an error. The page will show a short error reason.
- **Ready** — all good.

The card on the resumes list shows the same status.

## Case 1: it's been "Parsing" for more than 5 minutes

Most parsing finishes within a minute. If it's been ≥5 minutes, the queue is probably backed up or the worker isn't running.

**What to try:**

1. Refresh the page. Sometimes the status updates but the websocket missed the broadcast.
2. Click **Retry parse** on the detail page. This re-dispatches the job to the front of the queue.
3. If it still doesn't complete, contact whoever operates your install. On self-hosted installs, this usually means the queue worker (`queue:listen` or Horizon) stopped running.

**If you're the operator:**

- Check Horizon: `php artisan horizon:status`. If not running, start it.
- Check `storage/logs/laravel.log` for errors from `ParseResume` jobs.
- Check the `jobs` table (or Redis `queues:default` list) for stuck jobs.

## Case 2: "Parse failed — PDF had no extractable text"

This is a scanned PDF. Someone photographed or scanned a paper resume and saved it as PDF, but the file has no embedded text — it's just images.

**What to try:**

- Open the PDF in your reader and try to select text. If you can't select words, it's a scanned PDF.
- Re-export from Word, Google Docs, or whatever tool you wrote the resume in. Modern PDF exports have embedded text by default.
- If you only have the scan, you'll need to OCR it first. Tools: Adobe Acrobat ("Recognize text"), Preview on macOS (Tools → OCR), or any free online OCR. Then upload the OCR'd file.

Throughline doesn't do OCR itself — it's out of scope for v1.

## Case 3: "Parse failed — DOCX extraction error"

The DOCX has unusual content that broke our text extractor. Common culprits:

- Complex tables used for layout.
- Embedded SmartArt or shapes containing text.
- A DOCX built by a resume-generator tool that produced unusual XML.

**What to try:**

- Open the DOCX, copy-paste into a fresh document, save, re-upload. Most generated DOCX weirdness gets stripped by a round-trip through plain Word.
- Export the original as PDF and upload the PDF instead. PDF has different extraction paths and often succeeds where DOCX fails.

## Case 4: "Parse failed — Claude returned unparseable output"

Claude was called but returned output we couldn't parse as JSON. Usually because:

- The resume was very long (>12 pages) and Claude's output got truncated.
- The resume had a lot of non-English content that confused the prompt.
- It's a transient Claude API issue.

**What to try:**

- Click **Retry parse**. Transient issues usually clear on the second attempt.
- If the resume is very long, try trimming it to the most relevant 4–6 pages.
- If it keeps failing, contact the operator — they can look at logs for the Claude response.

## Case 5: "Parse failed — file corrupted"

The file couldn't be read at all. Usually an upload that was interrupted or a file that was damaged before upload.

**What to try:**

- Re-download the file from wherever you keep it and re-upload.
- Open it in Word / a PDF reader first to confirm it's readable.

## Case 6: the resume parses but all fields are blank

This is rare but happens with heavy-layout PDFs where extraction succeeds but returns little usable text. Claude then has nothing to work with.

**What to try:**

- Re-export from the source with simpler formatting (no multi-column layouts, no text boxes).
- Upload a DOCX version instead — DOCX extraction tends to produce cleaner text than PDF.

## Retry vs re-upload

**Retry parse** re-runs parse/embed against the *same file*. Use when you think the failure was transient (queue issue, Claude flakiness) — not a file problem.

**Re-upload** creates a fresh resume row. Use when you think the file itself is the problem (scanned PDF, corrupted file, bad layout). You can delete the failed resume after.

## Still stuck?

- [What we do with your resume](/help/concepts/how-matching-works) — background on what parsing produces.
- Contact the operator of your install, with the resume's error message.
