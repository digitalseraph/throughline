# External accounts and API keys

Throughline integrates with several external services. This doc walks you through exactly what to sign up for, where to click, and which values end up in your `.env`.

## This install's stack

| Layer | Provider | Why |
|---|---|---|
| Hosting (droplet) | **DigitalOcean** via Laravel Forge | Forge has first-class DO integration; smallest moving parts |
| Postgres + pgvector | **DigitalOcean Managed Postgres 16** | One-click pgvector extension |
| Redis | **DigitalOcean Managed Redis 7** | Same provider/billing as the droplet |
| Object storage | **DigitalOcean Spaces** | S3-compatible, same provider |
| DNS + edge | **Cloudflare** (free tier) | DDoS, edge cache for the public pages, free TLS |
| Mail | **Postmark** | Best transactional deliverability for password resets + reminders |
| Error monitoring | **Sentry** | Backend + frontend wired by default |
| Embeddings | **OpenAI** (`text-embedding-3-small`) | Required |
| Parse + rerank | **Anthropic Claude Sonnet 4.6** | Required |
| Auth | **Google OAuth + GitHub OAuth** | Optional but enabled |
| Job sources | **Adzuna + USAJobs** (plus the 6 unauthenticated drivers shipped in core) | Both keyed; Greenhouse / Lever / RemoteOK / Workable / Ashby / Remotive don't need accounts |

The two AI keys are the only **strictly required** services. Everything else is optional but enabled in this install.

## What breaks when a key is missing

| Service | What breaks without it |
|---|---|
| **OpenAI** | Resumes stall at parse, feed stays empty |
| **Anthropic (Claude)** | Resumes stall at parse; cards have no fit score / rationale |
| **Google OAuth** | "Continue with Google" button hidden; email/password signup still works |
| **GitHub OAuth** | "Continue with GitHub" button hidden; email/password signup still works |
| **Adzuna** | Adzuna source auto-pauses after 3 failures; other 7 drivers unaffected |
| **USAJobs** | USAJobs source auto-pauses; other drivers unaffected |
| **Postmark** | Email reminders + password resets fail to send. In dev, `MAIL_MAILER=log` writes them to the log file so you can verify them. |
| **DigitalOcean Spaces** | Resume + document uploads fail in production. In dev, files go to `storage/app/private/`; local works fine. |
| **Cloudflare** | DNS still works via the registrar; you lose edge caching + DDoS. Not strictly needed. |
| **Sentry** | Errors land in `storage/logs/laravel.log` only. Both SDKs no-op cleanly when DSN is blank. |
| **Reverb** (self-hosted) | Real-time push falls back to polling (30s). Full functionality preserved, just slower. |

## DigitalOcean (hosting + Postgres + Redis)

**What it's used for:** the Forge-managed droplet, managed Postgres 16 (with pgvector), managed Redis 7, and the Spaces bucket. Most provisioning is driven by Forge; this section covers the account + API token, the two managed database clusters, and the network wiring. Spaces has its own section below.

**Sign up:** [digitalocean.com](https://www.digitalocean.com) → add a payment method. Apply any promo credit you have.

### API token for Forge

Forge needs a DO API token to provision the droplet.

1. DO console → **API** → **Generate New Token**.
2. Name: `forge`, scopes: **Read + Write**, no expiration.
3. Paste into Forge → **Server Providers → DigitalOcean**.

### Managed Postgres 16 (with pgvector)

1. DO console → **Databases** → **Create Database Cluster** → **PostgreSQL** → version **16**.
2. Plan: **Dev DB** (1 GB RAM, ~$15/mo) is fine for personal scale; upsize later if you need it.
3. Region: same as the droplet (keeps latency <1 ms).
4. After creation: **Settings → Trusted Sources** → add the droplet's IP. This makes the DB reachable from the droplet only.
5. **Settings → Extensions** → enable `vector`. (Or run `CREATE EXTENSION IF NOT EXISTS vector;` from a psql session.)
6. **Connection Details** pane shows the host, port, user, password, and database name — paste into the `DB_*` block of `.env`.

### Managed Redis 7

1. DO console → **Databases** → **Create Database Cluster** → **Redis**.
2. Plan: Dev DB (1 GB) is plenty.
3. Region: same as the droplet.
4. Same trusted-sources setup: restrict to the droplet IP.
5. **Connection Details** → copy host / port / password. DO managed Redis uses TLS, so `REDIS_SCHEME=tls` and use `predis` (not `phpredis`).

### Env vars

```env
DB_CONNECTION=pgsql
DB_HOST=<from DO Postgres connection details>
DB_PORT=25060
DB_DATABASE=defaultdb
DB_USERNAME=doadmin
DB_PASSWORD=<from DO>
DB_SSLMODE=require

REDIS_CLIENT=predis
REDIS_HOST=<from DO Redis connection details>
REDIS_PORT=25061
REDIS_PASSWORD=<from DO>
REDIS_SCHEME=tls
```

### Cost (small install)

~$50/mo before droplet + mail + AI: droplet $18 + Postgres $15 + Redis $15 + Spaces $5.

---

## Cloudflare (DNS + edge)

**What it's used for:** DNS, edge caching for the public Welcome / About / Help pages, and free DDoS protection.

**Sign up:** [cloudflare.com](https://www.cloudflare.com) → free plan.

### Set up the zone

1. **Add a site** — enter your domain.
2. Cloudflare scans existing DNS records; review them.
3. Update your registrar's nameservers to the two Cloudflare-provided nameservers.
4. DNS → **Add record**:
   - Type: **A**
   - Name: `throughline` (or `@` for apex)
   - IPv4 address: your Forge droplet IP
   - Proxy status: **Proxied** (orange cloud)
5. **SSL/TLS → Overview** → set mode to **Full (strict)** so the Let's Encrypt cert Forge installs on the droplet is honored end-to-end.
6. **SSL/TLS → Edge Certificates** → enable **Always Use HTTPS** and **Automatic HTTPS Rewrites**.

### Reverb (WebSocket) on Cloudflare

The free plan supports WebSocket proxying out of the box — no extra config needed. The `/app/*` path for the Pusher-compatible protocol passes through Cloudflare transparently.

### Page rules (optional)

If you want to aggressively cache the public marketing surface:

- `throughline.yourdomain.com/help/*` → Cache Level: Cache Everything, Edge Cache TTL: 1 hour.
- `throughline.yourdomain.com/about` → same.

Don't cache `/` or any authenticated path.

### Sanity check

After DNS propagates (`dig +short throughline.yourdomain.com`), visit your site. The response headers should include `cf-ray` and `cf-cache-status` — that confirms you're proxied.

---

## OpenAI API key

**What it's used for:** `text-embedding-3-small` embeddings for resumes (per-user) and canonical jobs (system-attributed). Cost is tiny — $0.02 per 1M input tokens. A resume embed costs sub-cent; a job embed is similar.

### Sign up

1. Go to [platform.openai.com](https://platform.openai.com/) and create an account.
2. Navigate to **API keys** in the left sidebar.
3. Click **Create new secret key**. Name it "Throughline local" (or whatever).
4. **Copy the key immediately** — you can't see it again after closing the modal.

### Set it in `.env`

```env
OPENAI_API_KEY=sk-...paste-your-key-here...
OPENAI_ORGANIZATION=        # optional — leave blank unless you're on a team account
```

### Sanity check

Upload a resume in the app. Within ~10 seconds of upload the resume detail page should flip from "Parsing…" to "Ready to match". If it stays on "Parsing…", check `storage/logs/laravel.log` for OpenAI-related errors.

### Cost monitoring

Throughline records every OpenAI call in `ai_usage_events`. The [admin Analytics page](admin-guide.md#analytics) includes a daily AI spend chart. The `site_settings.cost_ceiling_per_user_per_day_usd` (default `5.00`) blocks a user's cost-attributed calls from exceeding that ceiling per UTC day.

---

## Anthropic API key

**What it's used for:** Claude Sonnet for resume parsing (extracts structured JSON from raw text) and stage-2 rerank (per-job fit score + rationale + gaps + narrative). Cost is ~$0.003/1K input tokens, $0.015/1K output tokens.

### Sign up

1. Go to [console.anthropic.com](https://console.anthropic.com/).
2. Navigate to **API keys**.
3. Click **Create Key**. Copy it.

### Set it in `.env`

```env
ANTHROPIC_API_KEY=sk-ant-...paste-your-key-here...
```

### Sanity check

After uploading a resume, the detail page eventually shows "Ready to match" and the feed starts producing cards with fit scores + rationale. The stage-2 rerank happens on first feed view for each set of top-K matches.

### Model selection

Default model is `claude-sonnet-4-6` for both resume parsing and rerank. Both are independently configurable via `site_settings`:

- `anthropic_parse_model` — used by `AiClient::parseResume`
- `anthropic_rerank_model` — used by `AiClient::rerank`

Edit via the admin [Settings page](admin-guide.md#settings).

---

## Google OAuth

**What it's used for:** "Continue with Google" button on login + register pages.

### Create the OAuth app

1. Go to [Google Cloud Console](https://console.cloud.google.com/).
2. Create a new project (or use an existing one). Name it something like "Throughline dev".
3. Navigate to **APIs & Services → OAuth consent screen**. Configure:
   - User type: **External** (unless you're on a Google Workspace and want internal-only).
   - App name: `Throughline`
   - Support email: your email
   - Scopes: add `.../auth/userinfo.email`, `.../auth/userinfo.profile`, `openid`
   - Test users: add your own email while in testing mode
4. Navigate to **APIs & Services → Credentials → Create Credentials → OAuth client ID**.
   - Application type: **Web application**
   - Name: `Throughline local`
   - Authorized redirect URIs: `http://localhost:8000/auth/google/callback`
   - (For production, also add `https://your-domain.com/auth/google/callback`)
5. Copy the **Client ID** and **Client Secret**.

### Set them in `.env`

```env
GOOGLE_CLIENT_ID=...your-client-id....apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-...your-secret...
GOOGLE_REDIRECT_URI=http://localhost:8000/auth/google/callback
```

### Sanity check

Go to `/login`. You should see a "Continue with Google" button. Click it, authorize the app, and you're signed in.

Production redirect URI: adjust `GOOGLE_REDIRECT_URI` to match `APP_URL` + `/auth/google/callback`.

---

## GitHub OAuth

**What it's used for:** "Continue with GitHub" button on login + register pages.

### Create the OAuth app

1. Go to [github.com/settings/developers](https://github.com/settings/developers) → **New OAuth App**.
2. Fill in:
   - Application name: `Throughline local`
   - Homepage URL: `http://localhost:8000`
   - Authorization callback URL: `http://localhost:8000/auth/github/callback`
3. Click **Register application**. On the next page, click **Generate a new client secret** and copy both the Client ID and the Secret.

### Set them in `.env`

```env
GITHUB_CLIENT_ID=your-client-id
GITHUB_CLIENT_SECRET=your-client-secret
GITHUB_REDIRECT_URI=http://localhost:8000/auth/github/callback
```

### Sanity check

On `/login`, click "Continue with GitHub". After authorizing, you're signed in.

For production, create a **separate GitHub OAuth app** with the production callback URL. GitHub OAuth apps are tied to a specific callback URL; using the same app for dev + prod is possible but not recommended.

---

## Adzuna (paginated job aggregator)

**What it's used for:** The `AdzunaDriver` pulls job listings from 14 country-scoped aggregator APIs. The seeded default is `us`.

### Sign up

1. Go to [developer.adzuna.com](https://developer.adzuna.com/) and create an account.
2. On the dashboard, your **App ID** and **App Key** are shown. Copy both.
3. Free tier gives ~1,000 calls/month. If you're running the ingestion scheduler aggressively, that cap may be hit — upgrade or dial down `fetch_cadence_minutes` in the admin job-sources page.

### Set them in `.env`

```env
ADZUNA_APP_ID=your-app-id
ADZUNA_APP_KEY=your-app-key
ADZUNA_MAX_PAGES=20          # optional — caps pagination per fetch
ADZUNA_RESULTS_PER_PAGE=50
```

### Sanity check

In the admin sources page, click "Fetch now" on the Adzuna row. Wait a minute. Run `docker exec throughline-postgres psql -U throughline -d throughline -c "SELECT count(*) FROM canonical_jobs WHERE source = 'adzuna';"` — you should see a non-zero row count.

### Other countries

Adzuna supports US, UK, AT, AU, BE, BR, CA, CH, DE, ES, FR, IN, IT, MX, NL, NZ, PL, RU, SG, ZA. To ingest from a different country:

1. Go to admin **Sources**, click **New source**.
2. source_type: `adzuna`, identifier: the country code (e.g., `gb`)
3. Click the edit pencil on the created row, set the `config_json` to `{"country": "gb"}` in the admin UI (or via `tinker` / a migration; see the [reference doc](reference.md#job-source-drivers)).

The `AdzunaDriver` maps country → ISO-4217 currency automatically for the 14 supported markets.

---

## USAJobs (US federal government)

**What it's used for:** `USAJobsDriver` pulls federal job postings. Free to use but requires registration with an API key + identifying User-Agent email.

### Sign up

1. Go to [developer.usajobs.gov](https://developer.usajobs.gov/) → **Request Authorization Key**.
2. Fill in the form: provide a name, email, and a description of your use case.
3. After approval (usually instant), you get:
   - `Authorization-Key` — your API key
   - An email you declared — used in the `User-Agent` header (USAJobs requires a contact email in UA per their API docs)

### Set them in `.env`

```env
USAJOBS_AUTH_KEY=your-authorization-key
USAJOBS_USER_AGENT_EMAIL=you@example.com
USAJOBS_MAX_PAGES=10
USAJOBS_RESULTS_PER_PAGE=25
```

### Sanity check

Admin sources → click "Fetch now" on the USAJobs row. Check `canonical_jobs` for rows with `source = 'usajobs'`.

### Scope

USAJobs is US-only. `validateIdentifier` accepts null (single-feed aggregator shape).

---

## Postmark (transactional email)

**What it's used for:** Password reset emails, email verification, and daily email reminders for applications with due `next_action_at`. In dev, `MAIL_MAILER=log` drops everything to `storage/logs/laravel.log`; you don't need Postmark locally.

### Sign up + verify your sending domain

1. Go to [postmarkapp.com](https://postmarkapp.com/) and create an account. Free trial sends 100 messages/month; paid starts at $15/mo for 10k.
2. Create a **Server** — name it `throughline-prod`.
3. Servers → `throughline-prod` → **Settings → API Tokens** → copy the **Server Token**.
4. Add a **Sender Signature** for your sending domain (`reminders@yourdomain.com`). Postmark shows you SPF and DKIM records — add them to Cloudflare. Without SPF/DKIM, Gmail and Outlook silently route your password resets and reminders to spam.

### Set it in `.env`

```env
MAIL_MAILER=postmark
POSTMARK_TOKEN=<your-server-token>
MAIL_FROM_ADDRESS="reminders@yourdomain.com"
MAIL_FROM_NAME="${APP_NAME}"
```

### Sanity check

In prod:

```bash
php artisan throughline:reminder-scan-now
```

Check Postmark → `throughline-prod` → **Activity**. Users with `notification_email_enabled=true` and applications with `next_action_at <= now()` and non-terminal status should have mail recorded.

In dev (`MAIL_MAILER=log`):

```bash
php artisan tinker
>>> $user = User::first();
>>> Mail::to($user)->send(new \App\Mail\ReminderDueMail($user, [Application::first()->id]));
```

Check `storage/logs/laravel.log` for the rendered mail.

---

## DigitalOcean Spaces (object storage)

**What it's used for:** Storing resume files (PDF/DOCX) and application documents (cover letters, portfolios, resume snapshots).

**Not needed in dev** — the local disk under `storage/app/private/` is fine.

### Create the bucket + access keys

1. DigitalOcean console → **Spaces Object Storage** → **Create Spaces Bucket**.
2. Region: pick the one closest to your droplet (same region = <1 ms latency).
3. Name: `throughline-prod-uploads`.
4. File listing: **Restricted** (private). Files are accessed only via the authenticated app, which streams them through a controller.
5. CDN: optional, not needed for private resume files.
6. Create.
7. Settings → **Spaces Access Keys** → **Generate New Key** → name it `throughline`. Copy both the access key and secret immediately.

### Configure in `.env`

```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<spaces-access-key>
AWS_SECRET_ACCESS_KEY=<spaces-secret>
AWS_DEFAULT_REGION=nyc3                           # or whichever region you picked
AWS_BUCKET=throughline-prod-uploads
AWS_ENDPOINT=https://nyc3.digitaloceanspaces.com  # match your region
AWS_USE_PATH_STYLE_ENDPOINT=false
```

The S3 SDK Laravel uses is Spaces-compatible — no custom driver needed.

### Sanity check

Upload a resume through the web UI in production. Then in the DO Spaces console, browse the bucket — you should see the file under the resume's storage path (typically `resumes/{uuid}.pdf`).

---

## Reverb (WebSocket server)

**What it's used for:** Real-time push for resume-ready, reminder badge, feed rerank arrival. Self-hosted; no external account.

### Dev

`composer run dev` starts Reverb automatically on port 8080 alongside PHP, Vite, and the queue listener. The `.env` vars in `.env.example` drive it.

### Production

Reverb is a long-running process. Run it as a systemd unit or supervisord process:

```ini
; /etc/supervisor/conf.d/reverb.conf
[program:throughline-reverb]
process_name=%(program_name)s
command=php /var/www/throughline/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=www-data
stdout_logfile=/var/log/supervisor/throughline-reverb.log
```

Production `.env`:

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=<generated>
REVERB_APP_KEY=<generated>
REVERB_APP_SECRET=<generated>
REVERB_HOST=reverb.your-domain.com
REVERB_PORT=443
REVERB_SCHEME=https
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

Note `REVERB_PORT=443` and `REVERB_SCHEME=https` when you put Reverb behind a reverse proxy with SSL termination. Otherwise set `REVERB_PORT=8080` and `REVERB_SCHEME=http`.

**Firewall:** open 8080 inbound, or proxy from 443 via nginx/Caddy. Reverb needs to be reachable by browsers, not just the PHP app.

---

## Sentry (error + performance monitoring)

**What it's used for:** capturing PHP exceptions across web, queue, and scheduler via `sentry/sentry-laravel` (wired in `bootstrap/app.php`), plus Vue runtime errors and unhandled promise rejections via `@sentry/vue` (wired in `resources/js/app.ts`). Free to start; leave the DSN blank to disable both SDKs.

### Sign up

1. Go to [sentry.io](https://sentry.io) and create a free account.
2. **Create Project** → platform **Laravel** → name it `throughline`.
3. Sentry shows the DSN — copy it. You can use the same DSN for the Vue side, or create a second project with platform **Vue** if you want to split frontend/backend events.

### Set them in `.env`

```env
# Backend
SENTRY_LARAVEL_DSN=https://...@o0.ingest.sentry.io/0
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_PROFILES_SAMPLE_RATE=0.1
SENTRY_SEND_DEFAULT_PII=false        # don't capture user emails / cookies
SENTRY_RELEASE=                      # optional: git SHA, set in deploy script
SENTRY_ENVIRONMENT=production        # optional: defaults to APP_ENV

# Frontend (interpolated from the backend DSN by default in .env.example)
VITE_SENTRY_DSN="${SENTRY_LARAVEL_DSN}"
VITE_SENTRY_ENVIRONMENT="${APP_ENV}"
VITE_SENTRY_TRACES_SAMPLE_RATE="${SENTRY_TRACES_SAMPLE_RATE}"
```

### What gets captured by default

- All unhandled PHP exceptions in web requests, queue jobs, and scheduled tasks.
- All unhandled Vue errors and promise rejections in the browser.
- A 10% sample of HTTP request traces and SQL queries.
- Breadcrumbs: cache events, SQL queries (without bindings), queue dispatches, HTTP client calls, log lines.
- The `/up` health endpoint is excluded from transactions to keep noise down.

### What's *not* captured

- User PII (email, IP, cookies) — `SENTRY_SEND_DEFAULT_PII=false` is the default.
- SQL query parameter bindings — bindings can contain credentials or user data.

### Sanity check

Trigger a synthetic error from tinker:

```bash
php artisan tinker --execute="throw new \RuntimeException('Sentry verification — ignore');"
```

The exception should appear in the project's Issues feed within a minute.

### Disable for local dev

Leave `SENTRY_LARAVEL_DSN` blank in your `.env`. The PHP SDK no-ops without a DSN, and the Vite tree-shaker drops `@sentry/vue` from the JS bundle entirely — dev installs pay zero overhead.

### Cost

The free tier (5k events / 10k transactions / 1 GB attachments per month) is plenty for a personal install. The 10% trace sample rate keeps you well under the transaction limit even on a busy day.

---

## What your production `.env` looks like with everything configured

Filled-in production env for this install (Forge → DO → Cloudflare → Postmark → Sentry). For an exhaustive variable list see [environment variables reference](reference.md#environment-variables).

```env
APP_NAME=Throughline
APP_ENV=production
APP_KEY=base64:...                                # php artisan key:generate --show
APP_DEBUG=false
APP_URL=https://throughline.yourdomain.com

# DigitalOcean Managed Postgres 16 + pgvector
DB_CONNECTION=pgsql
DB_HOST=<from DO connection details>
DB_PORT=25060
DB_DATABASE=defaultdb
DB_USERNAME=doadmin
DB_PASSWORD=<from DO>
DB_SSLMODE=require

# DigitalOcean Managed Redis 7 (TLS)
REDIS_CLIENT=predis
REDIS_HOST=<from DO connection details>
REDIS_PORT=25061
REDIS_PASSWORD=<from DO>
REDIS_SCHEME=tls

BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

# DigitalOcean Spaces (S3-compatible)
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<spaces-access-key>
AWS_SECRET_ACCESS_KEY=<spaces-secret>
AWS_DEFAULT_REGION=nyc3
AWS_BUCKET=throughline-prod-uploads
AWS_ENDPOINT=https://nyc3.digitaloceanspaces.com
AWS_USE_PATH_STYLE_ENDPOINT=false

# Postmark
MAIL_MAILER=postmark
POSTMARK_TOKEN=<server-token>
MAIL_FROM_ADDRESS="reminders@yourdomain.com"
MAIL_FROM_NAME="${APP_NAME}"

# AI
OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...

# OAuth
GOOGLE_CLIENT_ID=...apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-...
GOOGLE_REDIRECT_URI=https://throughline.yourdomain.com/auth/google/callback
GITHUB_CLIENT_ID=...
GITHUB_CLIENT_SECRET=...
GITHUB_REDIRECT_URI=https://throughline.yourdomain.com/auth/github/callback

# Job source drivers
ADZUNA_APP_ID=...
ADZUNA_APP_KEY=...
USAJOBS_AUTH_KEY=...
USAJOBS_USER_AGENT_EMAIL=you@yourdomain.com

# Reverb (TLS via Nginx → Cloudflare)
REVERB_APP_ID=throughline
REVERB_APP_KEY=<random 32+ chars>
REVERB_APP_SECRET=<random 32+ chars>
REVERB_HOST=throughline.yourdomain.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

# Sentry
SENTRY_LARAVEL_DSN=https://...@o0.ingest.sentry.io/0
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_PROFILES_SAMPLE_RATE=0.1
SENTRY_SEND_DEFAULT_PII=false
SENTRY_ENVIRONMENT=production
VITE_SENTRY_DSN="${SENTRY_LARAVEL_DSN}"
VITE_SENTRY_ENVIRONMENT="${APP_ENV}"
VITE_SENTRY_TRACES_SAMPLE_RATE="${SENTRY_TRACES_SAMPLE_RATE}"
```

For the local-dev equivalent (sqlite/Docker Postgres, MAIL_MAILER=log, Sentry blank), see `.env.example` in the repo root.

## Next steps

- [User guide](user-guide.md) — walk through the features you just enabled.
- [Forge deployment runbook](deployment-forge.md) — the concrete Forge + DigitalOcean + Cloudflare recipe (this install's deployment path).
- [Deployment overview](deployment.md) — topology, nginx, supervisor, scheduler reference (host-agnostic background).
