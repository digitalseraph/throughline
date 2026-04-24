# Deployment

How to get Throughline from a working local clone to a running production instance. Complements the [production readiness checklist](production-readiness.md), which is a pre-flight list; this doc is the step-by-step walkthrough.

## Target topology

A minimal Throughline production looks like this:

```
┌───────────────────────┐      ┌──────────────────────────┐
│  Load balancer / CDN  │─────▶│  Web server (nginx)      │
│  (SSL termination)    │      │  + php-fpm (app code)    │
└───────────────────────┘      └──────────────────────────┘
          │                                │
          ▼                                ▼
┌───────────────────────┐      ┌──────────────────────────┐
│  Reverb WebSocket     │      │  Background workers      │
│  server (port 8080)   │      │  (Horizon + Scheduler)   │
└───────────────────────┘      └──────────────────────────┘
                                         │
                                         ▼
┌────────────────────────┐   ┌──────────┐   ┌──────────────┐
│  PostgreSQL 16         │   │  Redis   │   │  S3 bucket   │
│  + pgvector extension  │   │          │   │  (uploads)   │
└────────────────────────┘   └──────────┘   └──────────────┘
```

Three runtime services on the app server (web + reverb + horizon), plus three managed data stores (Postgres, Redis, S3-compatible).

## Before you start

Have this ready:

- A Linux server (Ubuntu 22.04+ recommended) or container platform (Fly.io, Render, Railway, Laravel Forge, etc.).
- A domain name + SSL certificate strategy (Let's Encrypt works).
- A managed Postgres 16 with pgvector, OR a server where you'll install Postgres yourself.
- A managed Redis instance.
- S3 credentials (or equivalent for whatever S3-compatible storage you chose).
- All the API keys from [external accounts](external-accounts-setup.md).
- An SMTP provider configured and tested.
- Git access to the repo.

## Deploy target choices

### Option A: Laravel Forge / Ploi / Server management platforms

The smoothest path. These know Laravel conventions — they'll install PHP, configure php-fpm, install Horizon as a service, wire up SSL, run the scheduler. You bring a VPS (DigitalOcean droplet, AWS EC2, Hetzner server) and the platform handles the rest.

Forge deploy instructions:

1. Provision a server in Forge (pick Ubuntu 24.04, PHP 8.4).
2. Create a site — point it at your git repo + branch.
3. Add an "environment file" — paste your production `.env` content.
4. Add a deploy script (Forge's default works, plus these additions):
   ```bash
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan horizon:terminate
   ```
5. Under **Daemons**, add:
   - Command: `php /home/forge/your-site/artisan reverb:start --host=0.0.0.0 --port=8080`
   - User: `forge`
6. Under **Scheduler**, enable it (Forge wires cron automatically).
7. Configure SSL via LetsEncrypt.
8. Set up a **Horizon Daemon** — Forge has a one-click option, or add manually:
   - Command: `php /home/forge/your-site/artisan horizon`
9. Configure nginx to proxy WebSocket requests to port 8080 (see [nginx + Reverb](#nginx--reverb)).

### Option B: Fly.io / Render / Railway (container-based)

Works well but requires containerizing the app. A minimal `Dockerfile` for Throughline's app tier:

```dockerfile
FROM serversideup/php:8.4-fpm-nginx

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-dev
RUN php artisan config:cache route:cache view:cache

# Install pdftotext for PDF resume parsing
RUN apt-get update && apt-get install -y poppler-utils && rm -rf /var/lib/apt/lists/*
```

Build + push. On the platform side:

- One service for the web app (this image, exposing port 80).
- One service for Horizon (same image, command: `php artisan horizon`).
- One service for Reverb (same image, command: `php artisan reverb:start --host=0.0.0.0 --port=8080`).
- One cron-ish configuration for `php artisan schedule:run` every minute.

Use the platform's managed Postgres + Redis if available (Fly has both; Render has managed Postgres; Railway has both).

### Option C: Bare VPS

If you want the full control experience, provision an Ubuntu 22.04+ VPS and install everything:

```bash
# PHP 8.4 from the ondrej PPA
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install -y php8.4 php8.4-fpm php8.4-pgsql php8.4-redis php8.4-mbstring php8.4-xml php8.4-bcmath php8.4-zip

# Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# nginx
sudo apt install -y nginx

# Poppler for PDF extraction
sudo apt install -y poppler-utils

# Node for building frontend assets
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

# Supervisor for managing Horizon + Reverb
sudo apt install -y supervisor

# Git
sudo apt install -y git
```

Then the deploy is roughly:

```bash
sudo mkdir -p /var/www/throughline && sudo chown $USER:$USER /var/www/throughline
cd /var/www/throughline
git clone https://github.com/your-org/throughline.git .
composer install --no-dev --optimize-autoloader
npm ci
npm run build
cp .env.example .env
# edit .env with your production values
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=SiteSettingsSeeder --force
php artisan db:seed --class=JobSourcesSeeder --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Then [configure supervisor](#supervisor-configuration) and [nginx](#nginx-configuration).

## Production `.env`

Start from `.env.example` and fill in the following. The [external accounts doc](external-accounts-setup.md#what-your-env-looks-like-with-everything-configured) has a comprehensive example.

Critical differences from dev:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=pgsql
DB_HOST=your-postgres-host.example.com
DB_PORT=5432
DB_DATABASE=throughline
DB_USERNAME=throughline
DB_PASSWORD=<strong-password>

REDIS_HOST=your-redis-host.example.com
REDIS_PORT=6379
REDIS_PASSWORD=<strong-password>

BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

# Reverb with TLS via reverse proxy
REVERB_APP_ID=<generate with `php artisan reverb:install` or bin2hex(random_bytes)>
REVERB_APP_KEY=<generate>
REVERB_APP_SECRET=<generate>
REVERB_HOST=your-domain.com
REVERB_PORT=443
REVERB_SCHEME=https
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

# Mail
MAIL_MAILER=postmark   # or smtp, ses, mailgun, resend
POSTMARK_TOKEN=<your-token>
MAIL_FROM_ADDRESS="reminders@your-domain.com"
MAIL_FROM_NAME="Throughline"

# S3
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<your-key>
AWS_SECRET_ACCESS_KEY=<your-secret>
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=throughline-prod-uploads

# AI + OAuth + driver keys — see external-accounts-setup.md
```

After any `.env` change: `php artisan config:clear` then restart Horizon + Reverb + php-fpm.

## Database setup

### Managed Postgres

Most managed providers don't include pgvector by default. Check with the provider:

- **AWS RDS** — pgvector available on Postgres 16.
- **Google Cloud SQL** — pgvector available on Postgres 14+.
- **DigitalOcean Managed Postgres** — pgvector available on request.
- **Supabase** — pgvector enabled by default.
- **Neon** — pgvector enabled by default.

Verify:

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

Should succeed.

### Self-hosted Postgres

```bash
sudo apt install -y postgresql-16 postgresql-16-pgvector

sudo -u postgres psql <<SQL
CREATE DATABASE throughline;
CREATE USER throughline WITH ENCRYPTED PASSWORD 'your-password';
GRANT ALL PRIVILEGES ON DATABASE throughline TO throughline;
\c throughline
CREATE EXTENSION vector;
GRANT ALL ON SCHEMA public TO throughline;
SQL
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
