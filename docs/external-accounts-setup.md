# External accounts and services

Every account, API key, and OAuth app you need to run Throughline — what it's for, how to create it, and which env vars it maps to. Work through this *before* deploying so the env file in [`deployment-forge.md`](deployment-forge.md) (or your local `.env`) can be filled in completely.

## At a glance

| Service | Required for | Cost (small install) |
|---|---|---|
| OpenAI | Embeddings (resume + job) | ~$1–5/mo |
| Anthropic | Resume parsing + stage-2 rerank | ~$5–20/mo |
| Google Cloud Console | Sign in with Google (optional) | Free |
| GitHub Developer Settings | Sign in with GitHub (optional) | Free |
| Adzuna Developer Portal | Adzuna job source driver (optional) | Free |
| USAJobs Developer Portal | USAJobs job source driver (optional) | Free |
| DigitalOcean | Hosting (droplet + Postgres + Redis + Spaces) | ~$50/mo |
| Postmark or SendGrid | Transactional email | $0–15/mo |
| Cloudflare | DNS + edge (optional) | Free |

The two AI keys are the only **strictly required** external services. Everything else is optional or replaceable (different host, different mail provider, no OAuth at all).

---

## 1. OpenAI

**Used for:** generating 1536-dim embeddings of resumes and job postings via `text-embedding-3-small`. Every resume upload and every newly-ingested job triggers one embedding call.

**Sign up:** [platform.openai.com](https://platform.openai.com) → Settings → Billing → add a payment method.

**Create the key:**

1. API Keys → **Create new secret key**.
2. Name it `throughline-prod` (or `-dev`).
3. Scope: full access is fine; restrict to embeddings if you want.
4. Copy the key immediately — you can't see it again.

**Env vars:**

```env
OPENAI_API_KEY=sk-...
```

**Suggested rate limit:** Tier 1 (the default after first payment) gives 5,000 embeddings/min — comfortably above what a small install will hit. Bump if you ingest >50k jobs/day.

**Cost:** `text-embedding-3-small` is $0.02 per 1M input tokens. A typical resume is ~2k tokens; a typical job posting ~500 tokens. ~$1–5/mo for personal use.

---

## 2. Anthropic

**Used for:** Claude Sonnet 4.6 calls — resume parsing (one-shot per upload) and stage-2 rerank (one batch call per top-K window per profile, fired on feed view + nightly).

**Sign up:** [console.anthropic.com](https://console.anthropic.com) → Plans → add a payment method.

**Create the key:**

1. Settings → API Keys → **Create Key**.
2. Workspace: Default.
3. Copy the key.

**Env vars:**

```env
ANTHROPIC_API_KEY=sk-ant-...
```

**Cost:** rerank is the bulk of usage. A rerank batch is ~3k input + ~2k output tokens. Sonnet 4.6 is $3 per 1M input + $15 per 1M output. Typical user with daily feed checks: ~$5–20/mo. Caps are enforced server-side via `site_settings.cost_ceiling_per_user_per_day_usd`.

---

## 3. Google OAuth (sign in with Google)

**Used for:** the "Continue with Google" button on the login + register pages. Optional — the app works fine with email + password only.

**Sign up:** [console.cloud.google.com](https://console.cloud.google.com) — free Google account works.

**Create the project + credentials:**

1. **New Project**: name it `throughline`.
2. **APIs & Services → OAuth consent screen**:
   - User Type: External.
   - App name: `Throughline` (or your install's brand name).
   - Support email: yours.
   - Authorized domains: your production domain.
   - Scopes: `email`, `profile`, `openid` (the defaults).
   - Test users: add yourself if you keep the app in "Testing" status; otherwise submit for verification.
3. **APIs & Services → Credentials → Create Credentials → OAuth client ID**:
   - Application type: Web application.
   - Name: `Throughline Web`.
   - Authorized redirect URIs:
     - `https://throughline.yourdomain.com/auth/google/callback`
     - `http://localhost:8000/auth/google/callback` (for local dev)
4. Copy the **Client ID** and **Client Secret**.

**Env vars:**

```env
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
```

**Gotcha:** the redirect URI in the Google console must match your `APP_URL` exactly — wrong scheme or trailing slash will fail with `redirect_uri_mismatch`.

---

## 4. GitHub OAuth (sign in with GitHub)

**Used for:** the "Continue with GitHub" button. Same optionality as Google.

**Sign up:** GitHub account — already have one.

**Create the OAuth app:**

1. GitHub → Settings → **Developer settings** → **OAuth Apps** → **New OAuth App**.
2. Application name: `Throughline`.
3. Homepage URL: `https://throughline.yourdomain.com`.
4. Authorization callback URL: `https://throughline.yourdomain.com/auth/github/callback`.
5. **Register application**.
6. **Generate a new client secret**.
7. Copy the Client ID and the secret (the secret is only shown once).

For local dev, register a second OAuth app with `http://localhost:8000` URLs — GitHub doesn't allow multiple callback URIs per app.

**Env vars:**

```env
GITHUB_CLIENT_ID=...
GITHUB_CLIENT_SECRET=...
```

---

## 5. Adzuna (job source driver — optional)

**Used for:** the `AdzunaDriver` (paginated aggregator covering ~10 countries). Skip if you only run ATS drivers (Greenhouse, Lever, Workable, Ashby) and direct-feed drivers (RemoteOK, Remotive).

**Sign up:** [developer.adzuna.com](https://developer.adzuna.com) → free developer account.

**Get the credentials:**

1. After signup, the dashboard shows **App ID** and **App Key**.
2. Free tier: 1,000 calls/month. Each ingestion run uses ~5–20 calls per active country.

**Env vars:**

```env
ADZUNA_APP_ID=...
ADZUNA_APP_KEY=...
```

**Enable in admin:** the driver is registered automatically if both env vars are set. To actually fetch, go to `/admin/job-sources` and create an Adzuna source row (set country code, query terms, etc.).

---

## 6. USAJobs (job source driver — optional)

**Used for:** the `USAJobsDriver` (paginated, authenticated US federal jobs API). US-focused users only.

**Sign up:** [developer.usajobs.gov](https://developer.usajobs.gov) → request an API key. They email it.

**Env vars:**

```env
USAJOBS_AUTH_KEY=...
USAJOBS_USER_AGENT_EMAIL=you@yourdomain.com
```

The user-agent email is a USAJobs requirement — they bounce requests without one. Use a real address you check; they may email you about throttling.

---

## 7. DigitalOcean (hosting)

**Used for:** the Forge-managed droplet, the managed Postgres cluster (with pgvector), the managed Redis cluster, and the Spaces bucket for resume/document storage. Detailed wiring lives in [`deployment-forge.md`](deployment-forge.md); this section is just account setup.

**Sign up:** [digitalocean.com](https://www.digitalocean.com) → add a payment method. Apply any promo credit you have.

**What you'll create (driven by Forge in most cases):**

| Resource | Created via | Plan | ~Cost |
|---|---|---|---|
| Droplet (Ubuntu 22.04, 2 GB) | Forge UI | Basic | $18/mo |
| Managed Postgres 16 + pgvector | DO console | Dev DB (1 GB) | $15/mo |
| Managed Redis 7 | DO console | Dev DB (1 GB) | $15/mo |
| Spaces bucket | DO console | 250 GB included | $5/mo |

**API token:** Forge needs one to provision the droplet. DO console → API → **Generate New Token** → name it `forge`, scope `Read + Write`, no expiration. Paste into Forge → Server Providers → DigitalOcean.

**Spaces access keys:** DO console → Spaces → Settings → **Access Keys** → Generate. These map to `AWS_*` env vars (the S3 SDK is what Laravel uses; Spaces is S3-compatible).

**Env vars (object storage):**

```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=nyc3
AWS_BUCKET=throughline-prod
AWS_ENDPOINT=https://nyc3.digitaloceanspaces.com
AWS_USE_PATH_STYLE_ENDPOINT=false
```

---

## 8. Mail provider

**Used for:** password reset emails, email verification, daily application reminder emails (when due).

Pick one:

### Postmark (recommended)

- [postmarkapp.com](https://postmarkapp.com) → free trial (100/mo) or $15/mo for 10k.
- Verify your sending domain (SPF + DKIM via DNS records they provide).
- Servers → Default → API Tokens → copy the **Server Token**.

```env
MAIL_MAILER=postmark
POSTMARK_TOKEN=...
MAIL_FROM_ADDRESS="no-reply@yourdomain.com"
MAIL_FROM_NAME="${APP_NAME}"
```

### SendGrid

- [sendgrid.com](https://sendgrid.com) → free 100/day or paid plans from $15/mo.
- Verify domain in Settings → Sender Authentication.
- Settings → API Keys → Create → Full Access → copy.

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.sendgrid.net
MAIL_PORT=587
MAIL_USERNAME=apikey
MAIL_PASSWORD=SG....
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="no-reply@yourdomain.com"
MAIL_FROM_NAME="${APP_NAME}"
```

**Gotcha:** SPF + DKIM are mandatory in 2024+. Without them, Gmail and Outlook silently route your password resets and reminder emails to spam.

---

## 9. Cloudflare (DNS — optional but recommended)

**Used for:** DNS, edge caching of the public Welcome / About / Help pages, and free DDoS protection. Optional — your registrar's DNS works too.

**Sign up:** [cloudflare.com](https://www.cloudflare.com) → free plan.

**Set up:**

1. **Add a site** — enter your domain.
2. Cloudflare scans existing DNS records; review them.
3. Update your registrar's nameservers to the two Cloudflare-provided nameservers.
4. Add an **A record** pointing your subdomain (e.g., `throughline`) at your Forge server IP. Set proxy status to **proxied** (orange cloud).
5. SSL/TLS → Overview → set to **Full (strict)** so the Let's Encrypt cert on the server is honored end-to-end.

**Reverb gotcha:** Cloudflare's free plan supports WebSocket proxying — no extra config needed. If you're on Cloudflare Enterprise, ensure WebSockets are enabled on the zone.

---

## 10. Sentry (error + performance monitoring)

**Used for:** capturing PHP exceptions (web + queue + scheduler), Vue runtime errors, and a sampled slice of performance traces. Wired in by default — leave the DSN blank to disable.

**Sign up:** [sentry.io](https://sentry.io) → free tier covers 5k events/mo.

**Create the project:**

1. Sentry → **Create Project** → platform **Laravel**.
2. Name it `throughline`.
3. Sentry shows the DSN — copy it.
4. (Optional) Create a second project with platform **Vue** for the frontend, or reuse the Laravel DSN — events from both will land in the same project, tagged by SDK.

**Env vars:**

```env
# Backend
SENTRY_LARAVEL_DSN=https://...@o0.ingest.sentry.io/0
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_PROFILES_SAMPLE_RATE=0.1
SENTRY_SEND_DEFAULT_PII=false        # don't send user emails / cookies
SENTRY_RELEASE=                      # optional: git SHA injected at deploy time
SENTRY_ENVIRONMENT=production        # optional: defaults to APP_ENV

# Frontend (interpolated from the backend DSN by default in .env.example)
VITE_SENTRY_DSN="${SENTRY_LARAVEL_DSN}"
VITE_SENTRY_ENVIRONMENT="${APP_ENV}"
VITE_SENTRY_TRACES_SAMPLE_RATE="${SENTRY_TRACES_SAMPLE_RATE}"
```

**What gets captured by default:**

- All unhandled PHP exceptions in web requests, queue jobs, and scheduled tasks (via `Integration::handles()` in `bootstrap/app.php`).
- All unhandled Vue errors and unhandled promise rejections in the browser.
- A 10% sample of HTTP request traces and SQL queries (drop the sample rate or raise it via env).
- Breadcrumbs: cache events, SQL queries (without bindings), queue dispatches, HTTP client calls, log lines.
- The `/up` health endpoint is excluded from transactions to keep noise down.

**What's *not* captured:**

- User PII (email, IP, cookies) — `SENTRY_SEND_DEFAULT_PII=false` is the default.
- SQL query parameter bindings — bindings can contain credentials or user data.

**Cost:** the free tier (5k events / 10k transactions / 1 GB attachments per month) is plenty for a personal install. The 10% trace sample rate keeps you well under the transaction limit even on a busy day.

**Verify after deploy:** trigger a synthetic error from tinker and confirm it lands in Sentry:

```bash
php artisan tinker --execute="throw new \RuntimeException('Sentry verification — ignore');"
```

The exception should appear in the Sentry project's Issues feed within a minute.

**Disable for local dev:** leave `SENTRY_LARAVEL_DSN` blank in your `.env`. The SDK no-ops without a DSN, and the Vite tree-shaker drops `@sentry/vue` from the JS bundle entirely, so dev installs pay zero overhead.

---

## Order of operations for a fresh install

1. **OpenAI + Anthropic keys** — without these the app can't function at all.
2. **DigitalOcean account + API token** — needed before Forge can provision anything.
3. **Postgres cluster** (with pgvector) and **Redis cluster** — provision while you set up the droplet.
4. **Spaces bucket + access keys.**
5. **Mail provider** (Postmark/SendGrid) — verify SPF/DKIM early; DNS propagation can take hours.
6. **Domain DNS** (Cloudflare or registrar) — pointing at Forge server IP.
7. **Google + GitHub OAuth apps** — register *after* DNS is live, so the redirect URIs match your real `APP_URL`.
8. **Adzuna + USAJobs** — only if you plan to enable those drivers; can be added later via env + restart.

Once all the values are in hand, follow [`deployment-forge.md`](deployment-forge.md) end to end.
