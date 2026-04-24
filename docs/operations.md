# Operations

Day-to-day operation of a live Throughline install: monitoring, backups, scaling, incident response.

## Table of contents

- [Monitoring](#monitoring)
- [Logging](#logging)
- [Alerting](#alerting)
- [Backups](#backups)
- [Scaling](#scaling)
- [Runbook](#runbook)

---

## Monitoring

### Horizon dashboard (`/horizon`)

Your first stop. See [admin guide](admin-guide.md#horizon-queue-dashboard) for the full tour. Watch for:

- **Failed jobs counter** — should stay at 0 in steady state. Any non-zero value is a signal.
- **Throughput graph** — jobs/minute. Normal is determined by your ingestion cadence; unexpected flatlines mean a worker died.
- **Queue wait times** — under a few seconds in steady state. Spikes indicate a queue worker is falling behind.

### Admin Overview (`/admin`)

The source-health table at the bottom of the admin overview is your at-a-glance ingestion health. Green = healthy, amber = stale (>6h since last fetch), red = paused or errored.

### Admin Analytics (`/admin/analytics`)

- **Daily ingestion chart** — watch for gaps (a source went silent) or spikes (one source suddenly dominated).
- **AI spend chart** — your primary cost-observability surface. Compare against your monthly budget; if the trend climbs unexpectedly, it's usually a user with an uncapped ceiling or a rerank-loop bug.

### External uptime checks

Hit these from an external monitor (Pingdom, UptimeRobot, Better Stack, etc.):

- `https://your-domain.com/` — expect 200
- `https://your-domain.com/login` — expect 200
- `https://your-domain.com/broadcasting/auth` — expect 403 unauthenticated; 200 if you configure a test user session

Reverb doesn't have a built-in health check; a TCP check on port 8080 (or 443 if proxied) is a proxy for "is it running".

### Database metrics

For managed Postgres, your provider gives dashboards. For self-hosted, install `pg_stat_statements`:

```sql
CREATE EXTENSION pg_stat_statements;
```

Slow-query candidates in Throughline:

- `FanOutStage1Matches` inserts — cosine similarity across many jobs.
- `AdminAnalytics::dailyIngestion` — grouped aggregates over 30 days of `canonical_jobs`.
- Feed pagination — joins `job_matches` to `canonical_jobs`.

Watch for any query >100ms on a 1k-row dataset; that grows linearly.

### Redis metrics

Watch:

- **Memory usage** — Redis stores cache + queue + session data. Eviction policy on `maxmemory` should be `allkeys-lru` for caches.
- **Connected clients** — indicates whether Horizon + Reverb + app are all talking to Redis.
- **Slow log** — any Redis command over 10ms is unusual; investigate.

### PHP app metrics

Laravel Pulse (if you install it) is a solid one-page metrics dashboard. Without Pulse:

- Grep `storage/logs/laravel.log` for `ERROR` or `CRITICAL` frequency.
- php-fpm status page (configure `pm.status_path` in php-fpm pool config) for process-pool health.

---

## Logging

Throughline emits logs to the standard Laravel channels (`storage/logs/laravel.log` in the default config).

### Log levels you'll see

- `info` — scheduled job starts/ends, admin actions, email sends.
- `warning` — broadcast event fire failures (try/catch logged), AdminLogger write failures, driver fetch failures (before retries exhaust), cost-ceiling exceeded, provider rate limits.
- `error` — queue job exhausted retries, migration failures, uncaught exceptions.

### Centralizing logs

For production, configure a dedicated log driver:

```env
LOG_CHANNEL=stack
LOG_STACK=daily,papertrail     # or logtail, loggly, syslog, etc.
LOG_LEVEL=warning              # drop info in prod unless debugging
```

Laravel supports Papertrail, Logtail, Monolog handlers directly. See `config/logging.php`.

### Broadcast event warnings

Every `event(new ...)` broadcast dispatch is wrapped in try/catch:

```
[2026-04-22 14:30:15] production.WARNING: ResumeReady broadcast failed {"resume_id":42,"error":"Connection refused"}
```

If you see a stream of these, Reverb is down. Users see the 30-second polling fallback; nothing is broken, just slower.

### Queue worker failures

When a queue job exhausts its retries:

```
[2026-04-22 14:30:15] production.ERROR: Processed: App\Jobs\EmbedResume
```

The payload is stored in the `failed_jobs` table. Inspect via:

```bash
php artisan queue:failed
php artisan queue:retry <id>     # retry one
php artisan queue:retry all      # bulk retry
php artisan queue:flush          # delete all failed
```

Or use Horizon's **Failed** view in the UI.

---

## Alerting

### Minimum viable alerts

- **PagerDuty / Opsgenie / Better Stack incidents on:**
  - External uptime check failure (site returns 5xx or timeout) for >2 minutes.
  - Horizon failed-jobs count >5 per hour.
  - AI spend >2× the 7-day moving average (spending spike).
  - Auto-paused job sources (ping the admin overview source-health endpoint, alert if any row has `is_enabled=false AND last_error IS NOT NULL`).

- **Slack / email notifications (less urgent) on:**
  - Any source goes >12 hours without a successful fetch.
  - Any user hits the cost ceiling more than once per week (suggests their ceiling needs raising).
  - Reminder emails failing delivery.

### How to wire alerts

Throughline doesn't ship a built-in alerting engine. You have a few paths:

1. **Hosted metric platforms** — Datadog, New Relic, Honeybadger. They scrape logs + metrics, let you define alert rules.
2. **Laravel's own notifications** — write a scheduled job that checks conditions and dispatches notifications via Slack/email channels. Trivial to add; not in v1.
3. **Laravel Pulse** — ships dashboards but alerting is limited.

A pragmatic v1 stack: log everything to a centralized log platform (Papertrail / Logtail), define regex-based alerts there.

---

## Backups

### Postgres

**Nightly full dump + weekly compressed snapshot** is a solid baseline.

For managed Postgres, enable the provider's built-in backup:
- RDS: automated backups with 7-day retention.
- Supabase: daily snapshots on all plans.
- Neon: point-in-time restore.
- DO Managed Postgres: daily backups.

For self-hosted:

```bash
#!/bin/bash
# /usr/local/bin/throughline-backup.sh
BACKUP_DIR=/var/backups/throughline
mkdir -p "$BACKUP_DIR"
PGPASSWORD="$DB_PASSWORD" pg_dump -h "$DB_HOST" -U "$DB_USERNAME" -Fc -f "$BACKUP_DIR/$(date +%Y%m%d-%H%M).dump" "$DB_DATABASE"
# Retention: keep 30 days
find "$BACKUP_DIR" -name '*.dump' -mtime +30 -delete
# Upload to S3 for off-site
aws s3 sync "$BACKUP_DIR" "s3://throughline-backups/" --delete
```

Cron it nightly via root or a backup-specific user.

### What's actually critical to back up

- **Postgres** — contains everything (users, resumes content, applications, audit log). This is the source of truth.
- **S3 uploads** — resume files and application documents. Enable S3 bucket versioning for built-in recovery.
- **Redis** — intentionally ephemeral (cache + queue + session). A Redis loss means in-flight queue jobs are lost (recoverable via queue retry) and all users log out (annoying but not data-loss).
- **`.env`** — secrets. Keep a copy in a secure place (Vault, 1Password, AWS Secrets Manager). Never in version control.

### Restore test

Once a quarter, actually restore a backup to a scratch environment and verify the app boots + a random user's resume + applications are intact.

```bash
createdb throughline_restore_test
pg_restore -d throughline_restore_test /var/backups/throughline/20260401-0200.dump
# Update a copy of .env to point at throughline_restore_test
php artisan tinker  # poke around
```

---

## Scaling

### When to scale each piece

**Signs you need more app capacity:**
- php-fpm `max_children` exhausted repeatedly.
- Request latency >500ms at the p95.

**Fix:** Add app servers behind a load balancer. Sessions live in Redis, so any request can hit any server.

**Signs you need more queue workers:**
- Horizon's queue wait times climb past 30 seconds.
- Visible lag between user actions and their effects (e.g., "I saved a job but it took 10 seconds to show in my kanban").

**Fix:** Raise Horizon's supervisor `minProcesses` and `maxProcesses` in `config/horizon.php`. Or add dedicated queue-worker servers with their own Horizon supervisor.

**Signs you need more database capacity:**
- Postgres CPU >70% sustained.
- `pg_stat_activity` shows lots of `active` queries.

**Fix options:**
- Vertically scale (bigger instance).
- Add a read replica for admin analytics queries.
- Rebuild the pgvector index (see below).

**Signs you need to rebuild the pgvector index:**
- Feed queries are slow (joining `job_matches` to `canonical_jobs`).
- `FanOutStage1Matches` queries take > 500ms.

**Fix:**

```bash
php artisan throughline:rebuild-vector-index canonical_jobs
php artisan throughline:rebuild-vector-index resumes
```

The `lists` parameter auto-sizes to `ceil(sqrt(N))` per pgvector's recommendation. If you know your dataset shape, override:

```bash
php artisan throughline:rebuild-vector-index canonical_jobs --lists=100
```

This is a maintenance operation — runs in seconds for <100k rows, can be minutes for 1M+.

### Budget scaling

**AI cost control** — if a user hits the ceiling, they get "rerank unavailable" on their feed until midnight. Options:

1. Raise their ceiling via admin Settings (`cost_ceiling_per_user_per_day_usd`).
2. Accept it (the ceiling is doing its job).
3. For heavy users, consider splitting them to a separate per-user setting (not implemented in v1).

**Rerank cost control** — if the total rerank spend trends too high:
1. Reduce `rerank_shortlist_size` — fewer jobs reranked per feed view.
2. Increase `stage2_ttl_hours` — fresh rerank fires less often.
3. Disable stage-2 entirely via `rerank_enabled = false` — feed falls back to stage-1 order with placeholder rationale cards.

---

## Runbook

Common incidents and their resolutions.

### Incident: site returning 500s

1. Hit `/horizon` — is it reachable? If not, the whole app stack is down.
2. Check php-fpm status: `sudo systemctl status php8.4-fpm`. Restart if not running.
3. Check nginx: `sudo systemctl status nginx`. Restart if not running.
4. Tail `storage/logs/laravel.log` and look for recent exceptions.
5. If database connection errors: check Postgres is up + reachable from the app server.
6. If Redis connection errors: check Redis is up + reachable.

If you can reach `/horizon` but not the app, it's probably a route/config issue — likely a bad deploy. Roll back (`git checkout <previous-sha>`) and run `php artisan config:cache route:cache view:cache`.

### Incident: feed is empty for all users

1. Admin → Sources. Are any sources paused? Check `consecutive_failures` — 3 = auto-paused.
2. Admin → Jobs. Are there any `canonical_jobs`? If zero, ingestion hasn't run.
3. Check `/horizon` → pending jobs. Is `FetchJobSource` stuck?
4. `docker exec throughline-postgres psql -U throughline -d throughline -c "SELECT count(*) FROM canonical_jobs WHERE embedding IS NOT NULL;"` — are jobs embedded?
5. Dispatch a test fetch manually: admin → click "Fetch now" on Stripe. Wait ~1 minute. Check `canonical_jobs` again.

If only one user has an empty feed:

1. Check their search profile has a linked resume.
2. Check the resume's embedding column is non-null.
3. Check their hard filters aren't too strict.

### Incident: Claude API calls all failing

1. Check https://status.anthropic.com for an upstream outage.
2. If Anthropic is up: Admin → Error log → see what the failure modes are.
3. Check `ANTHROPIC_API_KEY` hasn't been rotated / invalidated.
4. If rate-limited: your usage spiked. The app auto-backoffs (released queue jobs). Wait or raise your Anthropic quota.

### Incident: OpenAI embedding calls all failing

Same as Claude. Check https://status.openai.com, verify `OPENAI_API_KEY`, watch Error log.

If OpenAI is down but Anthropic is up: resumes parse but don't embed; the feed stays stale. Users see the "Generating embedding…" state. When OpenAI recovers, the queued `EmbedResume` jobs retry automatically (Laravel's default retry-with-backoff).

### Incident: reminder emails not delivering

1. Run `php artisan throughline:reminder-scan-now` manually — does it write log entries?
2. Check your SMTP provider's outbound log. Any bounces?
3. Check `storage/logs/laravel.log` for `Mail\Mailer` errors.
4. Verify `MAIL_MAILER` in `.env` matches what your provider expects.
5. For Postmark/SendGrid: check your domain's SPF + DKIM records are correct.

If emails are being sent but users aren't receiving:
- Check spam folders.
- Verify `MAIL_FROM_ADDRESS` is on a domain you own with valid SPF/DKIM.

### Incident: real-time updates not landing

1. Check Reverb is running: `sudo supervisorctl status throughline-reverb`.
2. Check the WebSocket URL from the browser devtools Network tab. Any failed upgrades?
3. Verify `VITE_REVERB_*` env vars match your Reverb config. They're compiled into the JS bundle at build time — a mismatch means rebuild + redeploy frontend.
4. Firewall: ensure port 8080 (or 443 if proxied) is open.
5. Check the nginx WebSocket proxy config is correct (see [deployment doc](deployment.md#nginx-configuration)).

Fallback: users see polling-based updates (30s). Not broken, just slower.

### Incident: auto-paused source

An admin notices a red row on the admin overview or in the sources page.

1. Admin → Sources → edit the affected source. Read the `last_error` message.
2. Common causes:
   - Upstream API key rotated / invalidated (Adzuna, USAJobs).
   - Upstream schema changed (Greenhouse et al rarely, but possible).
   - Upstream rate-limited you hard.
3. Fix the underlying issue, then edit the source: set `is_enabled = true`, reset `consecutive_failures = 0` (manually via admin UI or tinker), click **Fetch now**.

If it fails again immediately, iterate. Drivers have unit tests against fixtures, so if the upstream changed shape, update the driver + its fixture + redeploy.

### Incident: AI spend spike

1. Admin → Analytics → AI spend chart. How big is the spike?
2. Check `ai_usage_events` for the hot day:
   ```sql
   SELECT user_id, operation, sum(estimated_cost_usd), count(*)
   FROM ai_usage_events
   WHERE created_at >= '2026-04-22 00:00:00'
   GROUP BY user_id, operation
   ORDER BY sum(estimated_cost_usd) DESC
   LIMIT 10;
   ```
3. Common causes:
   - New user with a huge resume being re-embedded.
   - Rerank loop from a bad prompt (user keeps hitting "retry" on the error log's rerank-unavailable section).
   - A driver pulled a larger-than-usual batch of jobs (unusual shape from an upstream).

4. Mitigation:
   - Raise the cost ceiling temporarily if it's a legitimate need.
   - Reduce `rerank_shortlist_size` until the cause is identified.
   - If a specific user is the culprit, their per-user ceiling still caps them; might not be a real problem.

### Incident: someone reports "I can't log in"

1. Check their account exists: Admin → Users → search by email.
2. Is `deactivated_at` set? If so, reactivate.
3. Did they register via OAuth and the OAuth app is broken (e.g., a redirect URI change)? Try email + password reset path.
4. Check `social_accounts` — did the provider_user_id change (rare, but possible if they migrated Google accounts)?

If password reset is broken too: check mail delivery.

### Incident: migration fails

1. Read the error carefully. Common causes:
   - Missing extension (`CREATE EXTENSION vector`) — run it manually against the database.
   - Data conflict (trying to add a NOT NULL column without a default on a populated table) — migration should have been written with a default or a two-step pattern. File a fix.
2. Fix forward: `php artisan migrate --force` once the underlying issue is resolved.
3. Don't `migrate:rollback` in production unless you've verified the `down()` method is safe. The pgvector ivfflat index operations don't have clean inverses.

---

## When to escalate

If you've tried the runbook and the issue persists, the underlying cause is likely in the code. Escalate to:

- **Search the design specs** at `docs/superpowers/specs/` for the plan that introduced the feature.
- **Read the implementation plan** at `docs/superpowers/plans/` for the task-level decisions.
- **Open a PR** with a regression test that reproduces the bug, then fix forward.

The codebase is small enough that a careful engineer with a failing test can produce a fix in a few hours. Most production issues in the first months are ingestion-related (driver quirks) or upstream-API-related (OpenAI/Anthropic/ATS outages).
