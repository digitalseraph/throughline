# Throughline — Production readiness checklist

## 1. Required env vars

- `APP_KEY` (`php artisan key:generate`)
- `APP_URL` (full https URL)
- `DB_*` (pgsql credentials to a Postgres 16 instance with pgvector)
- `REDIS_*` (Redis for cache + queue + session)
- `MAIL_*` (SMTP credentials — SendGrid, Postmark, etc.)
- `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`
- `ADZUNA_APP_ID`, `ADZUNA_APP_KEY` (if running the Adzuna driver)
- `USAJOBS_AUTH_KEY`, `USAJOBS_USER_AGENT_EMAIL` (if running USAJobs)
- `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`
- `BROADCAST_CONNECTION=reverb`
- `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` (compiled into JS bundle)
- S3 / filesystem creds if `FILESYSTEM_DISK != local`
- `SENTRY_LARAVEL_DSN` (optional but recommended); paired with `VITE_SENTRY_DSN` for frontend errors. Trace sampling defaults to 10% via `SENTRY_TRACES_SAMPLE_RATE`.

## 2. External services

- Postgres 16 + pgvector extension (`CREATE EXTENSION vector`)
- Redis 6+ (any managed Redis works)
- Reverb WebSocket server (run as supervisord / systemd service: `php artisan reverb:start --host=0.0.0.0 --port=8080`)
- S3-compatible object storage for resume + application document uploads
- OpenAI + Anthropic API keys with adequate rate limits
- SMTP provider for transactional mail
- Sentry project (optional) for backend + frontend error capture and sampled performance traces

## 3. Background processes

- Laravel Horizon (`php artisan horizon`) as a systemd service; set `horizon` as a queue worker supervisor
- Laravel scheduler (`* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`) via cron
- Reverb server (separate process — see above)

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

- Postgres: nightly `pg_dump`, weekly full, retain 30 days
- Redis: if using managed Redis with replication, no additional backup (state is ephemeral by design)
- S3: enable versioning on the bucket

## 8. Rollback

- Migrations: `php artisan migrate:rollback --step=N` works for most Plan 1-6 migrations. The pgvector ivfflat index rebuild has no inverse; manually recreate if needed.
- Queue drain: `php artisan horizon:terminate` + wait for in-flight jobs.

## 9. Smoke test post-deploy

- Register → upload resume → see "Ready to match" (Reverb working)
- Create profile → open feed → see ranked results with stage-2 rationale (rerank working)
- Save a job → drag through kanban (pipeline working)
- Admin login → `/admin` → see Overview cards populated (admin working)
