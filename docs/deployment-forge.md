# Deploying Throughline with Laravel Forge

A concrete runbook for shipping this app to production on Laravel Forge. Sits alongside [`deployment.md`](deployment.md) (the multi-target deployment guide covering Forge, Fly.io/Render/Railway, and bare VPS) — this doc is the focused Forge + DigitalOcean recipe with concrete UI steps and full env files. Pairs with [`production-readiness.md`](production-readiness.md) (env vars and external services) and [`external-accounts-setup.md`](external-accounts-setup.md) (every account you need to create first).

## What you'll provision

| Component | Recommendation | Why |
|---|---|---|
| App server | DigitalOcean droplet via Forge (2 GB / 2 vCPU minimum) | Forge has first-class DO integration; 2 GB fits PHP-FPM + Horizon + Reverb comfortably |
| Postgres | DigitalOcean Managed Postgres 16 | pgvector is a one-click extension; no sudo needed |
| Redis | DigitalOcean Managed Redis 7 | Cache + queue + session + Reverb all on one instance is fine for small scale |
| Object storage | DigitalOcean Spaces (S3-compatible) | Same provider, same billing, S3 API |
| Mail | Postmark or SendGrid | Transactional reliability matters for password resets + reminder emails |
| DNS | Cloudflare (free tier) | Proxy + DDoS + edge caching of the Welcome/About/Help pages |

Rough monthly cost: droplet $18 + managed Postgres $15 + managed Redis $15 + Spaces $5 + Postmark/SendGrid $10–15 = **~$65/mo** before AI costs.

If you want to save: use Hetzner Cloud for the droplet (~$6/mo for equivalent specs) but keep DO for the managed Postgres — pgvector is the constraint, and self-hosting it on the droplet makes backups and HA your problem.

## Prerequisites (do these before touching Forge)

1. **Create the external accounts** listed in [`external-accounts-setup.md`](external-accounts-setup.md). You need OpenAI, Anthropic, Google OAuth, GitHub OAuth, and whatever job-source drivers you plan to run (Adzuna, USAJobs).
2. **Create the managed Postgres cluster.** DO → Databases → Create → PostgreSQL 16. Under "Extensions", enable `vector`. Whitelist your Forge server IP (you'll add this after the droplet exists).
3. **Create the managed Redis cluster.** Same panel → Redis. Note the connection string and password.
4. **Create the Spaces bucket.** DO → Spaces. Generate an access key pair (Settings → Spaces → Access Keys). Note bucket name, region, endpoint URL.
5. **Set up the domain.** Point an A record at the Forge server IP (you'll get this in the next step).

## Step 1: Provision the app server

**Prereq:** connect your DO API token to Forge first. **Forge → Server Providers → DigitalOcean → Connect**. Paste the token you generated in `external-accounts-setup.md` (Read+Write scope, no expiration).

Then **Forge → Create Server** and fill in exactly this:

| Field | Value |
|---|---|
| **Provider** | DigitalOcean |
| **Credentials** | the token you just connected |
| **Server Type** | **App Server** |
| **Server Name** | `throughline-prod` |
| **Region** | the same region as your DO Managed Postgres + Redis clusters. Mismatched regions add ~30 ms of DB latency per query. Pick it once for all four DO resources. |
| **Server Size** | `s-2vcpu-2gb` (2 vCPU / 2 GB / 60 GB SSD, ~$18/mo) minimum. Bump to `s-2vcpu-4gb` (~$24/mo) for more headroom across Horizon + Reverb + php-fpm. Anything under 2 GB OOMs during `composer install`. |
| **Database Type** | **None** — we use DO Managed Postgres, not a local one |
| **Database Name** | leave blank (hidden when Type = None) |
| **PHP Version** | **PHP 8.4** |
| **Post-Provision Recipe** | leave unselected — we add the Poppler recipe in Step 2 |
| **Server Network (VPC)** | default (auto-created VPC is fine) |
| **Add to load balancer** | off |

Click **Create Server**. Provision takes ~5–10 minutes; watch the progress log.

### What Forge installs regardless

- Nginx (we use it).
- Local Redis (idle — we route `REDIS_HOST` to DO managed Redis).
- Supervisor (we use it for the Horizon + Reverb daemons).
- UFW (22, 80, 443 open; 8080 opens automatically when you add the Reverb daemon).
- A `forge` user with your Forge-registered SSH keys preloaded.
- Fail2Ban.

### After provisioning finishes

1. **Note the droplet's public IP** (shown on the server page in Forge).
2. **Add that IP to DO Managed Postgres → Settings → Trusted Sources.**
3. **Add that IP to DO Managed Redis → Settings → Trusted Sources.** Without steps 2 and 3, the app can't reach the databases.
4. **Add a Cloudflare A record**: DNS → Add record → type A, name `throughline` (or your subdomain), IPv4 = droplet IP, proxy status **Proxied** (orange cloud).

## Step 2: Install Poppler (PDF text extraction)

Throughline needs `pdftotext` for resume parsing. It's not in Forge's default stack.

- Forge → Server → **Recipes** → New Recipe → "Install Poppler":

  ```bash
  sudo apt-get update
  sudo apt-get install -y poppler-utils
  ```

- Run the recipe once on this server. Confirm: SSH in and run `pdftotext -v` — should print a version.

Forge recipes are useful because they re-run on server rebuilds automatically if you have to reprovision.

## Step 3: Create the site

Forge → your `throughline-prod` server → **Sites** tab → **Create Site**.

### 3a. Create Site form

| Field | Value |
|---|---|
| **Root Domain** | `throughline.yourdomain.com` (the domain the Cloudflare A record points at) |
| **Aliases** | leave blank |
| **Project Type** | **Laravel** |
| **Web Directory** | `/public` |
| **PHP Version** | **PHP 8.4** |
| **Create Database** | **unchecked** — we use DO Managed Postgres, not a local database |
| **Database Name** | (field hidden when unchecked) |
| **Isolation** | **off** — single-tenant droplet, not needed |
| **Wildcard Subdomain** | **off** |

Click **Add Site**. Forge creates the Nginx vhost and drops you onto the site's detail page.

### 3b. Install the Git repository

On the site detail page → **Apps** tab → **Install Repository**.

| Field | Value |
|---|---|
| **Provider** | **GitHub** (click "Connect with GitHub" if you haven't linked your account to Forge yet — one-time OAuth grant) |
| **Repository** | `digitalseraph/throughline` (or your fork) |
| **Branch** | `main` |
| **Install Composer Dependencies** | **checked** |

Click **Install Repository**. Forge clones the repo, runs `composer install --no-dev`, and generates a default deploy script. You'll replace that script in Step 6.

### 3c. Enable Quick Deploy (optional but recommended)

Site detail page → **Apps** tab → **Enable Quick Deploy**. Forge registers a webhook with GitHub so pushes to `main` trigger an automatic deploy. Skip this if you want manual-only deploys.

### 3d. SSL

Because Cloudflare is fronting the site in **Full (strict)** mode, the origin (your droplet) needs a cert Cloudflare trusts. There's a chicken-and-egg with Let's Encrypt here: Cloudflare tries to TLS-negotiate with the origin on 443 for every visitor, but the origin has no valid cert until you provision one. Until the cert is installed, visitors see **Cloudflare error 525 (SSL handshake failed).**

**Recommended: Cloudflare Origin Certificate (permanent, no renewal dance).** Cloudflare issues a free cert signed by its private CA, valid only for Cloudflare edge → origin traffic. Never expires (15-year default), no Let's Encrypt bootstrap, no cron.

1. **Cloudflare → SSL/TLS → Origin Server → Create Certificate.**
2. Key type: **RSA**, validity: **15 years**, hostnames: `throughline.yourdomain.com` (add `*.yourdomain.com` if you want to cover other subdomains too).
3. Copy the **Origin Certificate** PEM block and the **Private Key** PEM block — you only see the private key once.
4. **Forge → Site → SSL tab → Install Existing Certificate.** Paste the certificate and the private key. Save.
5. **Forge → Site → SSL tab → Activate** the new cert (click the activate icon on the row).
6. Visit `https://throughline.yourdomain.com` — should load cleanly. Cloudflare edge presents its public cert to the browser; Cloudflare-to-origin uses the Origin cert.

Caveat: the Origin cert is trusted **only** by Cloudflare, not by browsers. If you ever bypass the proxy (point DNS directly at the droplet), browsers will show a cert warning. That's fine when you're committed to Cloudflare.

**Alternative: Let's Encrypt.** Works but requires a one-time gray-cloud dance to obtain the first cert:

1. **Cloudflare → DNS → your A record → click the orange cloud to turn it gray** (DNS only, no proxy).
2. Wait ~30 seconds.
3. **Forge → Site → SSL tab → LetsEncrypt → Obtain Certificate.**
4. **Cloudflare → DNS → click the gray cloud back to orange.**
5. Keep Cloudflare at **Full (strict)**. Let's Encrypt auto-renews every ~60 days via Forge.

## Step 4: Environment variables

Forge → Site → Environment → Edit. Paste a filled-in version of this (pulling values from your accounts):

```env
APP_NAME="Throughline"
APP_ENV=production
APP_KEY=                                       # php artisan key:generate --show and paste
APP_DEBUG=false
APP_URL=https://throughline.yourdomain.com

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=                                       # from DO managed Postgres connection details
DB_PORT=25060
DB_DATABASE=defaultdb
DB_USERNAME=doadmin
DB_PASSWORD=
DB_SSLMODE=require                             # DO managed Postgres requires TLS

BROADCAST_CONNECTION=reverb
CACHE_STORE=redis
FILESYSTEM_DISK=s3
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

REDIS_CLIENT=predis
REDIS_HOST=                                    # from DO managed Redis
REDIS_PASSWORD=
REDIS_PORT=25061
REDIS_SCHEME=tls                               # DO managed Redis uses TLS

AWS_ACCESS_KEY_ID=                             # Spaces access key
AWS_SECRET_ACCESS_KEY=                         # Spaces secret
AWS_DEFAULT_REGION=nyc3                        # or whichever region
AWS_BUCKET=throughline-prod                    # your Spaces bucket name
AWS_ENDPOINT=https://nyc3.digitaloceanspaces.com
AWS_USE_PATH_STYLE_ENDPOINT=false

MAIL_MAILER=postmark                           # or smtp, ses, etc.
POSTMARK_TOKEN=
MAIL_FROM_ADDRESS="no-reply@yourdomain.com"
MAIL_FROM_NAME="${APP_NAME}"

OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...

# OAuth
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=

# Job source drivers (optional — enable what you want to run)
ADZUNA_APP_ID=
ADZUNA_APP_KEY=
USAJOBS_AUTH_KEY=
USAJOBS_USER_AGENT_EMAIL=you@yourdomain.com

# Reverb — pick any consistent strings for APP_ID/KEY/SECRET
REVERB_APP_ID=throughline
REVERB_APP_KEY=                                # random string, at least 32 chars
REVERB_APP_SECRET=                             # random string, at least 32 chars
REVERB_HOST=throughline.yourdomain.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

# Client-side Reverb (baked into the Vite build)
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

# Sentry (optional but recommended)
SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_PROFILES_SAMPLE_RATE=0.1
SENTRY_SEND_DEFAULT_PII=false
VITE_SENTRY_DSN="${SENTRY_LARAVEL_DSN}"
VITE_SENTRY_ENVIRONMENT="${APP_ENV}"
VITE_SENTRY_TRACES_SAMPLE_RATE="${SENTRY_TRACES_SAMPLE_RATE}"
```

Save. Forge writes this to `/home/forge/throughline.yourdomain.com/.env`.

## Step 5: Enable pgvector

The extension has to be enabled in the target database, not just the cluster.

> **Zero Downtime Deployment note:** Forge's Laravel template defaults to Zero Downtime Deployment, which means the actual code lives at `/home/forge/<site>/current/` (a symlink to the newest `releases/<timestamp>/`), not at the top-level site directory. If you `ls /home/forge/<site>/` and see `auth.json  current  releases  storage`, you're in ZDD mode — `cd current/` before running any artisan commands.

SSH into the server and run:

```bash
cd /home/forge/throughline.yourdomain.com/current
php artisan tinker --execute="DB::statement('CREATE EXTENSION IF NOT EXISTS vector');"
```

Or from a local psql against the cluster:

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

Either works. Do it before the first `migrate`.

## Step 6: Deploy script

Forge's Laravel template auto-generates a correct Zero Downtime Deployment script on site creation. The default looks like this and is what you want — **don't replace it** with a pre-ZDD `git pull` style script:

```bash
$CREATE_RELEASE()

cd $FORGE_RELEASE_DIRECTORY

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
$FORGE_PHP artisan optimize
$FORGE_PHP artisan storage:link
$FORGE_PHP artisan migrate --force

npm ci || npm install
npm run build

$ACTIVATE_RELEASE()

$RESTART_QUEUES()
```

What the directives mean:

- `$CREATE_RELEASE()` clones the tracked branch into a fresh `releases/<timestamp>/` directory.
- `$FORGE_RELEASE_DIRECTORY` points at that new directory so the rest of the script runs against it, not the live `current/` symlink.
- `artisan optimize` (Laravel 11+) caches `config`, `events`, `routes`, and `views` in one go — you don't need the four separate `*:cache` commands.
- `artisan storage:link` is idempotent; Forge keeps `storage/` at the site root (shared across releases) and links it from each new release.
- `$ACTIVATE_RELEASE()` atomically flips the `current` symlink to the new release. OPcache picks up new files by path; no FPM reload needed.
- `$RESTART_QUEUES()` restarts the Horizon daemon so the queue worker loads the new code.

### Reverb doesn't auto-restart — wire it up

`$RESTART_QUEUES()` only touches Horizon. Reverb is a separate Forge daemon and needs to restart after each deploy to load updated event classes.

- **Forge → Site → Daemons → Reverb daemon → enable "Restart After Deployment."**
- Do the same on the Horizon daemon if it isn't already (Forge sets this ON by default, but verify).

### Enable Quick Deploy

Site → Apps tab → **Enable Quick Deploy** (if you didn't already in Step 3c). Pushes to `main` will trigger the deploy script via GitHub webhook.

## Step 7: Horizon as a supervised daemon

Forge → Site → Daemons → New Daemon.

- **Command**: `php8.4 /home/forge/throughline.yourdomain.com/artisan horizon`
- **Directory**: `/home/forge/throughline.yourdomain.com`
- **User**: `forge`
- **Processes**: 1
- **Stop signal**: `SIGTERM`
- **Stop wait**: 30 seconds

Save. Forge writes a Supervisor config and starts the daemon.

Verify: in the browser, visit `https://throughline.yourdomain.com/horizon` as an admin user — the dashboard should load, and the ring should be green.

## Step 8: Reverb as a supervised daemon

Same UI, second daemon.

- **Command**: `php8.4 /home/forge/throughline.yourdomain.com/artisan reverb:start --host=0.0.0.0 --port=8080`
- **Directory**: `/home/forge/throughline.yourdomain.com`
- **User**: `forge`
- **Processes**: 1

Save.

Then **configure Nginx** to proxy WebSocket traffic to Reverb. Forge → Site → Nginx Configuration. Inside the existing `server { ... }` block, add this location *before* the final catch-all:

```nginx
location /app {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 60s;
    proxy_send_timeout 60s;
}
```

Save — Forge reloads Nginx. The `/app` path is what the Pusher-compatible protocol uses; the server-side Reverb process listens on it internally.

Verify: open `https://throughline.yourdomain.com` in a browser, open devtools → Network → WS, and the `pusher://` WebSocket connection should establish with status 101.

## Step 9: Scheduler

Forge → Server → Scheduler → New Scheduled Job.

- **Command**: `php8.4 /home/forge/throughline.yourdomain.com/artisan schedule:run`
- **Frequency**: Every minute
- **User**: `forge`

This fires the daily reminder scan, nightly rerank, ingestion cron, and anything else registered in `app/Console/Kernel.php`.

## Step 10: First deploy

Push your `main` branch — Quick Deploy runs the script. Watch Forge → Site → Deployments for the log.

Once the deploy completes, SSH in and run:

```bash
cd /home/forge/throughline.yourdomain.com/current
php artisan storage:link
php artisan db:seed --class=SiteSettingsSeeder --force
php artisan db:seed --class=JobSourcesSeeder --force
php artisan db:seed --class=InfrastructureCostsSeeder --force
```

These are one-time: `storage:link` creates the symlink Laravel needs for filesystem access; the three seeders populate the brand/site settings, the starter list of job sources (Greenhouse, Lever, RemoteOK, Workable, Ashby, Adzuna, Remotive, USAJobs), and the infrastructure cost line items (Forge, DO, Postmark, Sentry, etc.).

> Still in Zero Downtime Deployment mode — always `cd .../current` before artisan. On future deploys `current/` is a symlink to a fresh `releases/<timestamp>/` directory, but tinker and artisan commands always resolve correctly through the symlink.

## Step 11: Create the first admin

Because the auto-admin rule is "first user wins," you want to be that user. Visit `/register`, sign up with your real email, and you'll be admin on the first account. Or, if you prefer, create via tinker from `/home/forge/<site>/current`:

```bash
php artisan tinker
>>> \App\Models\User::factory()->create([
...     'name' => 'You',
...     'email' => 'you@yourdomain.com',
...     'password' => bcrypt('a-strong-password'),
...     'role' => 'admin',
...     'email_verified_at' => now(),
... ])
```

## Step 12: Smoke test

Walk through the critical paths:

- [ ] Visit `/` — landing page renders, Wordmark visible, About + Help links present.
- [ ] Visit `/about` — renders with tagline.
- [ ] Visit `/help` — help center index loads.
- [ ] Register a user — email verification works.
- [ ] OAuth sign-in — Google and GitHub both redirect and land on dashboard.
- [ ] Upload a resume — `pdftotext` runs, Claude parse completes, status → Ready.
- [ ] Create a profile linked to the resume.
- [ ] Admin → **Job Sources** → **Fetch now** on one source (Greenhouse is fastest for a smoke test) — ingestion writes canonical_jobs, match job fans out.
- [ ] Feed populates — cards show fit score and rationale.
- [ ] Save a job — application lands in Saved column.
- [ ] Horizon dashboard shows queue throughput (green metrics).
- [ ] Reverb WebSocket is connected (devtools → Network → WS).

## Day-2 operations

**Backups.** DigitalOcean Managed Postgres does daily backups (7-day retention on the base tier). If that's too short, bump the plan or run your own `pg_dump` cron.

**Log aggregation.** Laravel logs to `storage/logs/laravel.log` on the droplet. For real observability, add Papertrail / Logtail / BetterStack via Forge → Server → Log Management.

**Error tracking.** Drop a Sentry DSN into `SENTRY_LARAVEL_DSN` and `VITE_SENTRY_DSN` (the package is already installed and wired in `bootstrap/app.php` + `resources/js/app.ts`). Without a DSN both SDKs no-op and Vite tree-shakes Sentry out of the JS bundle, so dev/local installs pay zero overhead. See [`external-accounts-setup.md`](external-accounts-setup.md#10-sentry-error--performance-monitoring) for project setup.

**Scaling up.** If Horizon starts backing up, edit the daemon to run two processes, or bump the droplet. Horizon balances automatically across processes.

**pgvector index retuning.** After ~10k canonical_jobs, rebuild the ivfflat indexes for better recall. The command is in CLAUDE.md: `php artisan throughline:rebuild-vector-index canonical_jobs`.

**Secret rotation.** Env var changes in the Forge UI require a deploy to take effect in the cached config. After editing, click **Deploy Now** or push an empty commit.

## Troubleshooting

**Deploy fails at `composer install`:** make sure the Forge server has PHP 8.4 selected as its CLI version and `extension=pdo_pgsql` is enabled (Forge enables it by default for PostgreSQL projects, but confirm in `php --ini`).

**Horizon pings but processes don't run:** check Redis connectivity from the droplet (`redis-cli -u <connection string> ping`). Managed Redis over TLS requires `predis` not `phpredis` unless you compile the TLS support in.

**Reverb WebSocket 502/504:** the daemon isn't running or Nginx isn't forwarding `/app` correctly. Check Forge → Site → Daemons and the Nginx location block.

**pgvector errors on migrate:** extension isn't enabled. See Step 5.

**`pdftotext: command not found`:** recipe from Step 2 didn't run. Re-run it.

**Emails not arriving:** check Postmark → Activity for bounces. The most common cause is SPF/DKIM not configured for the sending domain.

**Horizon dashboard 403:** you're not admin. Check `users.role` in the database.
