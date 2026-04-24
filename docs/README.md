# Throughline documentation

Everything you need to run, extend, operate, and use Throughline.

## Start here

- [Getting started](getting-started.md) — clone to running app in ~20 minutes
- [External accounts and API keys](external-accounts-setup.md) — what you need to sign up for to get full functionality (OpenAI, Anthropic, Google, GitHub, Adzuna, USAJobs, SMTP)

## For users

- [User guide](user-guide.md) — resumes, search profiles, feed, applications, reminders

## For admins

- [Admin guide](admin-guide.md) — getting admin access, every page on `/admin`, impersonation, analytics, audit log, error log

## For developers

- [Architecture](architecture.md) — system overview, data model ER diagram, pipelines, real-time, security. Includes Mermaid diagrams.
- [Reference](reference.md) — CLI commands, broadcast events, `site_settings` keys, all 8 job-source drivers

## For operators

- [Deployment](deployment.md) — step-by-step production deploy walkthrough
- [Operations](operations.md) — monitoring, backups, scaling, runbook
- [Production readiness checklist](production-readiness.md) — pre-flight items before first deploy

## Design history (superpowers/)

The `superpowers/` subtree contains the design specs and implementation plans that built v1:

- `superpowers/specs/` — design decisions, scope fences, architecture choices
- `superpowers/plans/` — the task-by-task implementation plans

Useful context if you're extending the app or want to understand why something is the way it is.

## Quick links by role

| If you are... | Read these in order |
|---|---|
| New contributor | [Getting started](getting-started.md) → [Architecture](architecture.md) → [Reference](reference.md) |
| End user | [User guide](user-guide.md) |
| Admin / operator | [Admin guide](admin-guide.md) → [Operations](operations.md) |
| Deploying Throughline | [External accounts](external-accounts-setup.md) → [Deployment](deployment.md) → [Production readiness](production-readiness.md) |
| On-call | [Operations](operations.md) runbook section |
