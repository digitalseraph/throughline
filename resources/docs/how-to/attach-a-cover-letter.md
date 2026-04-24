---
title: Attach a cover letter or document
summary: Upload and keep per-application documents alongside your notes and contacts.
order: 5
tags: [applications, documents]
updated: 2026-04-24
---

Applications often come with per-role documents — a tailored cover letter, a specific resume version, a link to your portfolio, a writing sample. Throughline stores them with the application.

## Attach a document

1. Open the application detail page.
2. Scroll to **Documents**.
3. Pick a **kind**: Cover letter, Resume snapshot, Portfolio, or Other.
4. Choose the file. Max 20 MB. Allowed types: PDF, DOCX, PNG, JPEG, plain text.
5. Click **Upload**.

A `document_attached` event shows up in the timeline.

## What happens to the file

The file is stored on Throughline's configured disk (local storage in dev, S3 in production). It's **not publicly accessible** — only you, signed in, can download it.

Filenames are preserved as you uploaded them — if you named yours `acme-cover-letter-final-v3.pdf`, that's what you'll see in the list. The on-disk path is a hashed variant to prevent collisions and path traversal.

## Listing and removing

The **Documents** section on the application detail lists each file with its kind, size, and a **Remove** action.

Removing a document:

1. Deletes the database row.
2. Best-effort deletes the file from disk. If the file delete fails (disk error), the database row is still gone — we prefer a harmless file leak over a broken DB row pointing at a missing file.

## Kinds explained

- **Cover letter** — the custom pitch you wrote for this role.
- **Resume snapshot** — if you tailored a resume version specifically for this application, snapshot it here. Useful later if you want to remember what you applied with.
- **Portfolio** — portfolio PDF or screenshot. If your portfolio is a URL, use the contact's notes or application notes field instead.
- **Other** — anything else. Writing sample, reference letter, salary expectations sheet.

The kind doesn't gate what you can upload — it's just for organization.

## Related

- [Tracking an application end-to-end](/help/tutorials/tracking-an-application-end-to-end)
