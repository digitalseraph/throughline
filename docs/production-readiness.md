# Throughline — Production readiness checklist

This install runs on **Laravel Forge → DigitalOcean (droplet + managed Postgres + managed Redis + Spaces) → Cloudflare**, with **Postmark** for mail and **Sentry** for errors. Detailed runbook: [`deployment-forge.md`](deployment-forge.md). Account setup: [`external-accounts-setup.md`](external-accounts-setup.md).

## 1. Required env vars

- `APP_KEY` (`php artisan key:generate`)
- `APP_URL` (full https URL, e.g. `https://throughline.yourdomain.com`)
- `DB_*` — DigitalOcean Managed Postgres 16 + pgvector (`DB_PORT=25060`, `DB_SSLMODE=require`)
- `REDIS_*` — DigitalOcean Managed Redis 7 (`REDIS_PORT=25061`, `REDIS_SCHEME=tls`, `REDIS_CLIENT=predis`)
- `MAIL_MAILER=postmark` + `POSTMARK_TOKEN`
- `FILESYSTEM_DISK=s3` + `AWS_*` for DigitalOcean Spaces (`AWS_ENDPOINT=https://<region>.digitaloceanspaces.com`)
- `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`
- `GOOGLE_CLIENT_ID/SECRET`, `GITHUB_CLIENT_ID/SECRET` (OAuth enabled in this install)
- `ADZUNA_APP_ID`, `ADZUNA_APP_KEY`
- `USAJOBS_AUTH_KEY`, `USAJOBS_USER_AGENT_EMAIL`
- `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT=443`, `REVERB_SCHEME=https`, `REVERB_SERVER_PORT=8080`
- `BROADCAST_CONNECTION=reverb`
- `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` (compiled into the JS bundle at build time)
- `SENTRY_LARAVEL_DSN` + `VITE_SENTRY_DSN`. Trace + profile sampling defaults to 10% via `SENTRY_TRACES_SAMPLE_RATE` / `SENTRY_PROFILES_SAMPLE_RATE`. PII off via `SENTRY_SEND_DEFAULT_PII=false`.

The full filled-in example lives in [`external-accounts-setup.md`](external-accounts-setup.md#what-your-production-env-looks-like-with-everything-configured).

## 2. External services

- DigitalOcean Managed Postgres 16 with `vector` extension enabled (one-click in the DO console).
- DigitalOcean Managed Redis 7 (TLS-enabled, `predis` client).
- DigitalOcean Spaces (private bucket) for resume + application document uploads.
- Reverb WebSocket server running on the droplet via Forge daemon (`php artisan reverb:start --host=0.0.0.0 --port=8080`); Nginx proxies `/app` → `127.0.0.1:8080`.
- OpenAI + Anthropic API keys with sane rate limits (Tier 1 is fine for personal scale).
- Postmark for transactional mail with verified SPF + DKIM on the sending domain (records added at Cloudflare).
- Sentry project (Laravel platform) for backend + frontend error capture and sampled performance traces.
- Cloudflare zone proxying the domain (orange-cloud A record at the Forge droplet IP); SSL/TLS mode **Full (strict)**.

## 3. Background processes (Forge daemons)

- Horizon: `php8.4 /home/forge/<site>/artisan horizon` (1 process, stop signal `SIGTERM`, stop wait 30s).
- Reverb: `php8.4 /home/forge/<site>/artisan reverb:start --host=0.0.0.0 --port=8080` (1 process).
- Laravel scheduler: Forge → Server → Scheduler → `php8.4 /home/forge/<site>/artisan schedule:run`, every minute.

After deploys, the deploy script runs `php artisan horizon:terminate` so Horizon picks up new code.

## 4. First deploy

- `php artisan migrate --force`
- `php artisan db:seed --class=SiteSettingsSeeder --force`
- `php artisan db:seed --class=JobSourcesSeeder --force`
- `php artisan storage:link`
- `npm run build` for Vite assets

## 5. Monitoring

- Horizon dashboard at `/horizon` (admin-gated); watch failed-jobs count
- Sentry (if DSN configured): backend exceptions land via `Integration::handles()` in `bootstrap/app.php`; frontend errors land via `@sentry/vue`. Free tier covers ~5k events / 10k transactions per month at the default 10% sample rate.
- Log aggregation — centralize `storage/logs/laravel.log`
- Alert on: failed queue jobs > N/hour, auto-paused job sources, AI cost ceiling hits
- Optional: Laravel Pulse for request/queue/cache metrics

## 6. Security

- `APP_KEY` rotation: coordinate with session drivers; rotating without session migration logs everyone out
- `SESSION_DRIVER=redis` in prod (not `array` or `file`)
- CSP: Reverb adds a `ws://` connection — include your Reverb host in `connect-src`
- Rotate API keys periodically; use the Settings editor only for non-secret tunables

## 7. Backups

- Postgres: DigitalOcean Managed Postgres takes daily backups with 7-day retention on the base tier. Bump the plan or run your own `pg_dump` cron if you need longer retention.
- Redis: managed Redis is replicated; no additional backup (state is ephemeral by design — sessions, cache, queue).
- DigitalOcean Spaces: enable versioning in the bucket settings.

## 8. Rollback

- Migrations: `php artisan migrate:rollback --step=N` works for most Plan 1-6 migrations. The pgvector ivfflat index rebuild has no inverse; manually recreate if needed.
- Queue drain: `php artisan horizon:terminate` + wait for in-flight jobs.

## 9. Smoke test post-deploy

- Register → upload resume → see "Ready to match" (Reverb working)
- Create profile → open feed → see ranked results with stage-2 rationale (rerank working)
- Save a job → drag through kanban (pipeline working)
- Admin login → `/admin` → see Overview cards populated (admin working)
