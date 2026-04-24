---
title: Add a recruiter or interviewer contact
summary: Attach people to an application — recruiters, hiring managers, interviewers, referrals.
order: 4
tags: [applications, contacts]
updated: 2026-04-24
---

Each application can have any number of contacts. Add them as you go; they stay with the application for reference later.

## Add a contact

1. Open **Applications** and click the card for the application you want.
2. Scroll to the **Contacts** section.
3. In the inline form:
   - **Name** — required.
   - **Role** — pick one: Recruiter, Hiring manager, Interviewer, Referral, Other.
   - **Email** — optional, email format validated.
4. Click **Add contact**.

A `contact_added` event appears in the timeline. The contact is listed above the form.

## Edit or remove a contact

Each contact row has a **Remove** link. Click to delete.

V1 doesn't have a visual edit affordance in the inline list; to edit, remove and re-add. (Future plans may surface an edit button.)

You can add phone, LinkedIn URL, and notes via the API but the inline form is minimal — add the ones you need, skip the rest.

## What contacts are for

The obvious use: remembering who your recruiter is when you need to follow up three weeks later.

The less-obvious use: pattern matching across applications. Same recruiter at multiple companies (common in tech). Same hiring manager that rejected you once but might be hiring again elsewhere. The contact list is small, per-application context — but spending a minute per application to add the one or two people who matter pays off months later.

## Related

- [Tracking an application end-to-end](/help/tutorials/tracking-an-application-end-to-end)
