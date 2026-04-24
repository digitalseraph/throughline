# Deployment

This install's deployment topology and the host-agnostic background reference (nginx, supervisor, scheduler, SSL, rollback). For the concrete step-by-step on Laravel Forge + DigitalOcean + Cloudflare, see [`deployment-forge.md`](deployment-forge.md).

## Target topology

```
                          ┌──────────────────────┐
                          │  Cloudflare          │
                          │  (DNS + edge + TLS)  │
                          └──────────┬───────────┘
                                     │
                          ┌──────────▼───────────┐
                          │  Nginx (Forge)       │
                          │  + php-fpm 8.4       │
                          └──────────┬───────────┘
                                     │
        ┌────────────────────────────┼────────────────────────────┐
        ▼                            ▼                            ▼
┌──────────────────┐      ┌──────────────────────┐    ┌────────────────────┐
│ Reverb daemon    │      │ Horizon daemon       │    │ Cron scheduler     │
│ (WebSockets,     │      │ (queue worker)       │    │ (artisan           │
│  port 8080,      │      │                      │    │  schedule:run)     │
│  proxied at /app)│      │                      │    │                    │
└──────────────────┘      └──────────────────────┘    └────────────────────┘
                                     │
        ┌────────────────────────────┼────────────────────────────┐
        ▼                            ▼                            ▼
┌──────────────────────┐   ┌──────────────────┐    ┌──────────────────────┐
│ DO Managed Postgres  │   │ DO Managed Redis │    │ DO Spaces            │
│ 16 + pgvector (TLS)  │   │ 7 (TLS)          │    │ (resumes + docs)     │
└──────────────────────┘   └──────────────────┘    └──────────────────────┘

                          ┌──────────────────────┐
                          │ Sentry (errors +     │
                          │ traces, backend +    │
                          │ frontend)            │
                          └──────────────────────┘

                          ┌──────────────────────┐
                          │ Postmark (mail)      │
                          └──────────────────────┘
```

Three runtime services on the droplet (web/php-fpm + Reverb + Horizon, plus the cron scheduler), three managed data stores at DO (Postgres, Redis, Spaces), Cloudflare in front, Sentry and Postmark out of band.

## Before you start

You need:

- All the keys + accounts in [external accounts](external-accounts-setup.md). The chosen stack is locked in there.
- A domain name pointed at Cloudflare nameservers.
- A DigitalOcean account + API token (Forge uses it to provision the droplet).
- A Laravel Forge subscription.
- Git access to the repo.

## Deploy: this install

Follow [`deployment-forge.md`](deployment-forge.md) end to end — that's the concrete Forge runbook for this install. The rest of this doc is reference material for the moving parts the runbook touches (nginx, supervisor, scheduler, SSL, rollback).

### Alternative deployment targets (not used here)

If you ever migrate off Forge, the moving parts are unchanged. The substitutes that worked when this app was prototyped:

- **Fly.io** — multi-process Docker app, managed Postgres with pgvector, native WebSocket support. `fly.toml` defines `web` / `worker` (Horizon) / `reverb` / `scheduler` from a single image.
- **Render / Railway** — one service per process; bills per service so cost climbs with the process split.
- **Bare VPS** — Ubuntu 22.04+ with manually installed PHP 8.4 / nginx / Supervisor / poppler-utils. Same nginx + supervisor configs as below.

These aren't documented in detail because this install runs on Forge.

## Production `.env`

The full filled-in example for this install lives in [external-accounts-setup.md → "What your production .env looks like"](external-accounts-setup.md#what-your-production-env-looks-like-with-everything-configured). Critical differences from local dev:

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://throughline.yourdomain.com`.
- `DB_*` points at DigitalOcean Managed Postgres (`DB_PORT=25060`, `DB_SSLMODE=require`).
- `REDIS_*` points at DigitalOcean Managed Redis (`REDIS_PORT=25061`, `REDIS_SCHEME=tls`, `REDIS_CLIENT=predis` — DO managed Redis uses TLS which `phpredis` can't speak without custom compilation).
- `FILESYSTEM_DISK=s3` plus `AWS_*` credentials for DO Spaces (`AWS_ENDPOINT=https://nyc3.digitaloceanspaces.com` or your region).
- `MAIL_MAILER=postmark` + `POSTMARK_TOKEN`.
- `REVERB_PORT=443`, `REVERB_SCHEME=https` (TLS terminated at Cloudflare → Nginx; Reverb itself listens on `REVERB_SERVER_PORT=8080` internally).
- `SENTRY_LARAVEL_DSN` + `VITE_SENTRY_DSN` set; sample rates default to 0.1.

After any `.env` change in Forge: click **Deploy Now** (or push an empty commit). The cached config picks up changes only on a deploy.

## Database setup

The chosen Postgres is **DigitalOcean Managed Postgres 16** with the `vector` extension enabled at cluster creation time. Detailed setup is in [external-accounts-setup.md → DigitalOcean](external-accounts-setup.md#digitalocean-hosting--postgres--redis).

If pgvector wasn't enabled at creation time, enable it from a psql session against the cluster:

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

Or from the droplet via tinker:

```bash
php artisan tinker --execute="DB::statement('CREATE EXTENSION IF NOT EXISTS vector');"
```

### First migration

From the deployed app:

```bash
php artisan migrate --force
php artisan db:seed --class=SiteSettingsSeeder --force
php artisan db:seed --class=JobSourcesSeeder --force
```

The seeders are idempotent (`firstOrCreate` for site_settings, `updateOrCreate` for job_sources). Safe to re-run on subsequent deploys.

## Nginx configuration

Example production nginx config (adjust paths for your install):

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;

    root /var/www/throughline/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;

    # Reverb WebSocket proxy — requests to /app/<reverb-app-key>/... hit Reverb
    location /app/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_read_timeout 3600s;
    }

    # Reverb also uses /apps/... for health + auth
    location /apps/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny hidden files + common config files
    location ~ /\.(?!well-known) { deny all; }
    location ~ /\.env              { deny all; }

    # Asset caching
    location ~* \.(css|js|jpg|jpeg|png|gif|ico|svg|woff2)$ {
        expires 30d;
        add_header Cache-Control "public";
    }
}

server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$server_name$request_uri;
}
```

Reload:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

## Supervisor configuration

### Horizon (queue worker)

`/etc/supervisor/conf.d/throughline-horizon.conf`:

```ini
[program:throughline-horizon]
process_name=%(program_name)s
command=php /var/www/throughline/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/throughline-horizon.log
stopwaitsecs=3600
```

The `stopwaitsecs=3600` gives Horizon an hour to finish in-flight jobs on a graceful stop. Important during deploys: `php artisan horizon:terminate` inside your deploy script is the correct way to signal restart.

### Reverb (WebSocket server)

`/etc/supervisor/conf.d/throughline-reverb.conf`:

```ini
[program:throughline-reverb]
process_name=%(program_name)s
command=php /var/www/throughline/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/throughline-reverb.log
```

Reload supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start throughline-horizon throughline-reverb
sudo supervisorctl status
```

## Cron for the Laravel scheduler

Add to `www-data`'s crontab (`sudo -u www-data crontab -e`):

```
* * * * * cd /var/www/throughline && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler dispatches `FetchJobSource`, `NightlyRerankRefresh`, `CloseStaleJobs`, and `ApplicationReminderScan` at the right cadences.

## Deployment workflow

### Zero-downtime deploys

The stock Laravel pattern:

```bash
#!/bin/bash
set -e

cd /var/www/throughline

php artisan down --render="errors::503" --retry=60

git fetch origin
git reset --hard origin/main

composer install --no-dev --optimize-autoloader
npm ci
npm run build

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Restart Horizon so it picks up new code
php artisan horizon:terminate

# Restart Reverb too (it runs old code until restart)
sudo supervisorctl restart throughline-reverb

php artisan up
```

The `artisan down` → `artisan up` block provides a maintenance window. If you run blue/green, you skip the `down/up` but still need to terminate + restart Horizon/Reverb so workers load new code.

### Rolling back

```bash
cd /var/www/throughline
git checkout <previous-commit-sha>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate:rollback --step=<N>  # if migrations changed
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan horizon:terminate
sudo supervisorctl restart throughline-reverb
```

Careful with `migrate:rollback` — the pgvector `ivfflat` index recreations don't have inverses. Inspect the migration's `down()` method before rolling back.

## SSL / HTTPS

Let's Encrypt via certbot:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
```

Auto-renewal is handled by the certbot systemd timer (`certbot renew --dry-run` to verify).

## Post-deploy smoke test

After every deploy, walk through this:

1. Hit the homepage — should load.
2. Register a fresh test account → dashboard renders.
3. Upload a resume (small test PDF) → "Ready to match" surfaces within ~15s (includes a buffer for Reverb to hand off).
4. Create a search profile → redirect to list works.
5. Admin user (first-user-auto-admin from production): navigate to `/admin` → overview cards populated.
6. Admin → sources → click "Fetch now" on a Greenhouse board → wait a minute → `/admin/jobs?source=greenhouse` shows rows.
7. Reopen the feed → stage-2 cards render.
8. Save a card → kanban shows it.
9. Open `/horizon` → no failed jobs; throughput chart ticking.

If any step fails, `storage/logs/laravel.log` + Horizon's failed-jobs view are your first stops.

## Scaling beyond v1

Throughline v1 is single-server-friendly. When you outgrow it:

- **Horizontally scale app servers** — nginx + php-fpm behind a load balancer. Sessions live in Redis, so any app server can handle any request.
- **Horizon workers** — move to dedicated queue-worker servers. Add more processes via Horizon's supervisors config (`config/horizon.php`).
- **Reverb scaling** — Reverb supports horizontal scaling via Redis pub/sub. See Laravel docs for multi-instance Reverb.
- **Postgres read replicas** — add a read replica for analytics queries. Laravel's `sticky` read-write split config can route admin analytics to the replica.
- **pgvector index retuning** — as `canonical_jobs` grows, run `php artisan throughline:rebuild-vector-index canonical_jobs` — the default ceil(sqrt(N)) lists parameter is pgvector's rule of thumb.
- **AI cost budgeting** — beyond raising `cost_ceiling_per_user_per_day_usd`, consider per-org caps, tiered rerank models (Haiku for low-priority, Sonnet for top-K), or caching common rerank results.

## Next steps

- [Production readiness checklist](production-readiness.md) — final pre-flight before first deploy.
- [Operations doc](operations.md) — monitoring, backups, and the runbook for when something's wrong.
