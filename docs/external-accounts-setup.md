# External accounts and API keys

Throughline integrates with several external services. This doc walks you through exactly what to sign up for, where to click, and which values end up in your `.env`.

None of the keys are mandatory for the app to *run* — it won't crash without them. But each missing key degrades a specific feature. The table below shows what breaks when each is absent.

| Service | Required for | What breaks without it |
|---|---|---|
| **OpenAI** | Resume + job embeddings | Resumes stall at parse, feed stays empty |
| **Anthropic (Claude)** | Resume parsing + stage-2 rerank | Resumes stall at parse; cards have no fit score / rationale |
| **Google OAuth** | "Continue with Google" button | Email/password signup still works |
| **GitHub OAuth** | "Continue with GitHub" button | Email/password signup still works |
| **Adzuna** | `AdzunaDriver` paginated aggregator | Adzuna source auto-pauses after 3 failures; other 7 drivers unaffected |
| **USAJobs** | `USAJobsDriver` federal jobs | USAJobs source auto-pauses; other drivers unaffected |
| **SMTP provider** (SendGrid, Postmark, SES, Mailgun, etc.) | Email reminder delivery in production | In dev, `MAIL_MAILER=log` writes reminders to the log file so you can verify them |
| **S3-compatible storage** | Resume + application document storage in production | In dev, files go to `storage/app/private/`; local works fine |
| **Reverb** (self-hosted) | Real-time push for resume ready / nav badge / feed | App falls back to polling (30s). Full functionality preserved, just slower. |

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

## SMTP (production email)

**What it's used for:** Daily email reminders for applications with due `next_action_at`. In dev, `MAIL_MAILER=log` drops them to `storage/logs/laravel.log`; you don't need SMTP locally.

### Choose a provider

Any Laravel-supported provider works. Common choices: SendGrid, Postmark, Amazon SES, Mailgun, Resend.

### Generic SMTP setup

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.your-provider.example.com
MAIL_PORT=587
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="reminders@your-domain.com"
MAIL_FROM_NAME="Throughline"
```

### Laravel-native drivers

- **Postmark:** `MAIL_MAILER=postmark`, `POSTMARK_TOKEN=...`
- **SendGrid:** use the SMTP config above with `MAIL_HOST=smtp.sendgrid.net`, `MAIL_USERNAME=apikey`, `MAIL_PASSWORD=<your api key>`.
- **SES:** `MAIL_MAILER=ses`, `AWS_ACCESS_KEY_ID=...`, `AWS_SECRET_ACCESS_KEY=...`, `AWS_DEFAULT_REGION=us-east-1`.
- **Resend:** `MAIL_MAILER=resend`, `RESEND_KEY=...`.

### Sanity check

In prod:

```bash
php artisan throughline:reminder-scan-now
```

Check your provider's outbound log. Users with opted-in `notification_email_enabled` and applications with `next_action_at <= now()` and non-terminal status should receive a mail.

In dev (`MAIL_MAILER=log`):

```bash
php artisan tinker
>>> $user = User::first();
>>> Mail::to($user)->send(new \App\Mail\ReminderDueMail($user, [Application::first()->id]));
```

Check `storage/logs/laravel.log` for the rendered mail.

---

## S3-compatible object storage (production)

**What it's used for:** Storing resume files (PDF/DOCX) and application documents (cover letters, portfolios, resume snapshots).

**Not needed in dev** — the local disk under `storage/app/private/` is fine.

### Any S3-compatible service

Throughline uses Laravel's filesystem layer; any S3-API-compatible storage works: AWS S3, DigitalOcean Spaces, Cloudflare R2, Backblaze B2, MinIO.

### Configure in `.env`

```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=throughline-prod-uploads
AWS_ENDPOINT=                   # only needed for non-AWS S3-compatible (DO Spaces, R2, etc.)
AWS_USE_PATH_STYLE_ENDPOINT=false
```

### Bucket setup

- **Private bucket** (no public read). Files are accessed only via the authenticated app, which streams them through a controller.
- **Enable versioning** (recommended for recovery).
- Set a **lifecycle rule** to expire orphaned files after 30 days, if you want.

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

## What your `.env` looks like with everything configured

A fully-configured `.env` has these sections filled in (see [environment variables reference](reference.md#environment-variables) for the exhaustive list):

```env
APP_NAME=Throughline
APP_ENV=local
APP_KEY=base64:...
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=throughline
DB_USERNAME=throughline
DB_PASSWORD=throughline

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...

GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=http://localhost:8000/auth/google/callback

GITHUB_CLIENT_ID=...
GITHUB_CLIENT_SECRET=...
GITHUB_REDIRECT_URI=http://localhost:8000/auth/github/callback

ADZUNA_APP_ID=...
ADZUNA_APP_KEY=...

USAJOBS_AUTH_KEY=...
USAJOBS_USER_AGENT_EMAIL=you@example.com

REVERB_APP_ID=throughline
REVERB_APP_KEY=throughline-key-dev
REVERB_APP_SECRET=throughline-secret-dev
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

MAIL_MAILER=log
MAIL_FROM_ADDRESS="reminders@throughline.local"
MAIL_FROM_NAME="${APP_NAME}"

SENTRY_LARAVEL_DSN=                  # leave blank to disable
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_PROFILES_SAMPLE_RATE=0.1
SENTRY_SEND_DEFAULT_PII=false
VITE_SENTRY_DSN="${SENTRY_LARAVEL_DSN}"
VITE_SENTRY_ENVIRONMENT="${APP_ENV}"
VITE_SENTRY_TRACES_SAMPLE_RATE="${SENTRY_TRACES_SAMPLE_RATE}"
```

## Next steps

- [User guide](user-guide.md) to walk through the features you just enabled.
- [Deployment](deployment.md) for the general production walkthrough, or [Forge deployment runbook](deployment-forge.md) for a concrete Forge + DigitalOcean recipe.
