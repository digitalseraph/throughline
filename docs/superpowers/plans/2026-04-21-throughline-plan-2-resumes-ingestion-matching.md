# Throughline — Plan 2: Resumes, Search Profiles, Ingestion & Stage-1 Matching

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the first vertical slice that takes Throughline end-to-end — user uploads a resume, creates a search profile, and sees a ranked feed of jobs pulled by three drivers — with the Plan-3 card shell in place and a placeholder rationale slot. Ship the full `AiClient` wrapper (Redis token bucket, per-user daily cost ceiling, `ai_usage_events` audit table) so Plan 3-6 never have to retrofit instrumentation.

**Architecture:** Dockerized Postgres 16 + pgvector replaces the Windows-native install. `AiClient` singleton fronts OpenAI + Anthropic with attribution, ceiling, and rate-limiting baked in. `JobSourceDriver` contract + `DriverRegistry` cleanly separate driver shape from ingestion logic. Queued jobs chain parse → embed → fan-out stage-1 matches, each idempotent. Per-entity vector storage (`resumes.embedding`, `jobs.embedding`) — no shared vector table.

**Tech Stack:** PHP 8.4, Laravel 12, Inertia v2 + Vue 3 + TypeScript, Tailwind v3, pgvector, Redis, Horizon (prod only), OpenAI `text-embedding-3-small`, Anthropic Claude Sonnet 4.6, `spatie/pdf-to-text`, `phpoffice/phpword`, Playwright for E2E.

**Spec reference:** [`docs/superpowers/specs/2026-04-21-throughline-plan-2-design.md`](../specs/2026-04-21-throughline-plan-2-design.md) (Plan 2 design sub-spec). Main product spec: [`docs/superpowers/specs/2026-04-21-throughline-design.md`](../specs/2026-04-21-throughline-design.md).

---

## Preconditions

Verify at the repo root before starting Task 1:

```bash
docker --version                            # expect: 24.0+
composer --version                          # expect: 2.x
node --version                              # expect: v20+
php --version                               # expect: PHP 8.4.x
redis-cli ping                              # expect: PONG (if you plan to reuse host Redis; Docker-provided Redis is fine too)
```

Stop any host Postgres 18 service currently listening on 5432 (`net stop postgresql-x64-18` in an elevated shell, or equivalent). Plan 2 assumes 5432 is free for the Docker container.

---

## Task index

1. Docker Postgres + pgvector, CLAUDE.md Postgres-version correction
2. `ai_usage_events` table + `AiClient` scaffolding (classes, exceptions, service binding)
3. `AiClient` implementation — token bucket, cost ceiling, OpenAI + Anthropic calls
4. Resumes table, model, and `ParseResume` + `EmbedResume` + `RecomputeStage1ForResume` stubs
5. Resume upload controller + manager UI
6. Search profiles: schema, model, CRUD controller, UI
7. `job_sources` + `jobs` schema, models, `JobSourcesSeeder`
8. `JobSourceDriver` contract, `DriverRegistry`, `CanonicalJob` DTO, `GreenhouseDriver`
9. `LeverDriver` + fixture-driven test
10. `RemoteOkDriver` + fixture-driven test
11. `FetchJobSource` + `EmbedJob` queued jobs + scheduler
12. `job_matches` schema + `FanOutStage1Matches` + real `RecomputeStage1ForResume`
13. Feed controller, page, `JobMatchCard` component, rationale slot
14. Admin job-sources CRUD page + "fetch now" action
15. Playwright E2E smoke + CLAUDE.md + README updates + full verification

---

## Task 1: Docker Postgres + pgvector

**Files:**
- Create: `compose.yaml`
- Create: `database/migrations/2026_04_21_100000_enable_pgvector_extension.php`
- Modify: `.env`
- Modify: `.env.example`
- Modify: `CLAUDE.md` (Postgres-version note — full docs rewrite lands in Task 15)
- Modify: `README.md` (dev-loop instructions — full rewrite lands in Task 15)

- [ ] **Step 1: Stop Windows-native Postgres 18**

Open an elevated PowerShell and run:

```powershell
net stop postgresql-x64-18
sc.exe config postgresql-x64-18 start= demand
```

Expected: service stopped and set to manual start so the dev loop doesn't re-bind 5432. If the service name differs, run `Get-Service | Where-Object { $_.Name -like "postgres*" }` to find the correct name.

- [ ] **Step 2: Write `compose.yaml`**

Create `E:/dev/repos/throughline/compose.yaml`:

```yaml
services:
  postgres:
    image: pgvector/pgvector:pg16
    container_name: throughline-postgres
    environment:
      POSTGRES_DB: throughline
      POSTGRES_USER: throughline
      POSTGRES_PASSWORD: throughline
    ports:
      - "5432:5432"
    volumes:
      - throughline-pgdata:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U throughline -d throughline"]
      interval: 5s
      timeout: 3s
      retries: 10

  redis:
    image: redis:7-alpine
    container_name: throughline-redis
    ports:
      - "6379:6379"
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 3s
      retries: 10

volumes:
  throughline-pgdata:
```

- [ ] **Step 3: Start the stack**

```bash
docker compose up -d
docker compose ps
```

Expected: `throughline-postgres` and `throughline-redis` both `healthy`. If Redis can't bind 6379, stop any host Redis instance first.

- [ ] **Step 4: Update `.env` and `.env.example`**

Edit `.env`:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=throughline
DB_USERNAME=throughline
DB_PASSWORD=throughline

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
```

Apply the same keys to `.env.example`, leaving `DB_PASSWORD=` blank (sentinel, not `throughline`).

- [ ] **Step 5: Write the pgvector migration**

Create `database/migrations/2026_04_21_100000_enable_pgvector_extension.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS vector');
    }
};
```

- [ ] **Step 6: Run migrations against Docker Postgres**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan migrate --no-interaction
```

Expected: pgvector migration runs; existing Plan-1 migrations re-run on the fresh database. Verify pgvector is live:

```bash
docker exec throughline-postgres psql -U throughline -d throughline -c "SELECT extname, extversion FROM pg_extension WHERE extname = 'vector';"
```

Expected: a single row `vector | 0.7.x` (or newer).

- [ ] **Step 7: Seed Plan-1 data**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan db:seed --no-interaction
```

Expected: `SiteSettingsSeeder` runs; no errors.

- [ ] **Step 8: Run Plan-1 test suite against Docker Postgres**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --compact
```

Expected: all Plan-1 tests pass. If any fail due to DB connection, double-check `DB_*` values in `.env`.

- [ ] **Step 9: Patch CLAUDE.md Postgres version note**

Edit `CLAUDE.md`. Replace the stack line:

```
- PostgreSQL 16 + Redis + Horizon (Horizon can't `horizon:work` on Windows — Linux-only via `ext-pcntl`)
```

with:

```
- PostgreSQL 16 + Redis + Horizon — both run via `compose.yaml` in dev (`docker compose up -d`). Horizon can't `horizon:work` on Windows — Linux-only via `ext-pcntl`; dev uses `queue:listen`.
```

Replace the "Local setup" block so it reads:

```markdown
## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
docker compose up -d
php artisan migrate --seed
composer run dev
```
```

(The full CLAUDE.md rewrite lands in Task 15; this step is the minimum fix so the doc isn't misleading mid-plan.)

- [ ] **Step 10: Commit**

```bash
git add compose.yaml database/migrations/2026_04_21_100000_enable_pgvector_extension.php .env.example CLAUDE.md
git commit -m "$(cat <<'EOF'
feat: Docker Postgres 16 + pgvector for dev + extension migration

Replaces Windows-native Postgres 18 with the pgvector/pgvector:pg16
container. Adds redis:7-alpine alongside so the full dev stack spins
up via `docker compose up -d`. New migration enables the vector
extension on fresh databases.

CLAUDE.md patched for the version change and new pre-dev command.
Full docs rewrite deferred to Plan 2 Task 15.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `ai_usage_events` table + `AiClient` scaffolding

**Files:**
- Create: `database/migrations/2026_04_21_100100_create_ai_usage_events_table.php`
- Create: `app/Models/AiUsageEvent.php`
- Create: `app/Services/Ai/AiClient.php` (scaffolding only — real implementation in Task 3)
- Create: `app/Services/Ai/Exceptions/CostCeilingExceeded.php`
- Create: `app/Services/Ai/Exceptions/ProviderRateLimited.php`
- Create: `app/Services/Ai/Exceptions/ParseFailed.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `database/seeders/SiteSettingsSeeder.php`

- [ ] **Step 1: Write the migration**

`database/migrations/2026_04_21_100100_create_ai_usage_events_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 32);
            $table->string('model', 128);
            $table->string('operation', 32);
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->decimal('estimated_cost_usd', 10, 6)->default(0);
            $table->string('context_type', 32)->nullable();
            $table->string('context_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['provider', 'model', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }
};
```

- [ ] **Step 2: Write the Eloquent model**

`app/Models/AiUsageEvent.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'provider',
        'model',
        'operation',
        'input_tokens',
        'output_tokens',
        'estimated_cost_usd',
        'context_type',
        'context_id',
        'created_at',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'estimated_cost_usd' => 'decimal:6',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 3: Write the typed exceptions**

`app/Services/Ai/Exceptions/CostCeilingExceeded.php`:

```php
<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

class CostCeilingExceeded extends RuntimeException
{
    public function __construct(
        public readonly int $userId,
        public readonly float $currentSpendUsd,
        public readonly float $ceilingUsd,
    ) {
        parent::__construct(sprintf(
            'User %d has reached the daily AI cost ceiling (spent $%.4f of $%.2f).',
            $userId,
            $currentSpendUsd,
            $ceilingUsd,
        ));
    }
}
```

`app/Services/Ai/Exceptions/ProviderRateLimited.php`:

```php
<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

class ProviderRateLimited extends RuntimeException
{
    public function __construct(
        public readonly string $provider,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct(sprintf(
            'Provider %s rate-limited this call; retry after %ds.',
            $provider,
            $retryAfterSeconds,
        ));
    }
}
```

`app/Services/Ai/Exceptions/ParseFailed.php`:

```php
<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

class ParseFailed extends RuntimeException
{
    public function __construct(string $reason, public readonly ?string $rawResponse = null)
    {
        parent::__construct("Resume parse failed: {$reason}");
    }
}
```

- [ ] **Step 4: Write the `AiClient` scaffolding**

`app/Services/Ai/AiClient.php` (methods throw `\LogicException` — the real bodies land in Task 3):

```php
<?php

namespace App\Services\Ai;

use App\Models\User;

class AiClient
{
    protected ?User $actingUser = null;

    public function forUser(?User $user): self
    {
        $clone = clone $this;
        $clone->actingUser = $user;

        return $clone;
    }

    public function embed(string $text, string $context): array
    {
        throw new \LogicException('AiClient::embed not yet implemented — see Plan 2 Task 3.');
    }

    public function parseResume(string $rawText): array
    {
        throw new \LogicException('AiClient::parseResume not yet implemented — see Plan 2 Task 3.');
    }
}
```

- [ ] **Step 5: Bind `AiClient` as a singleton**

Edit `app/Providers/AppServiceProvider.php`. In `register()`, alongside the existing `SiteSettings` + `BrandConfig` bindings, add:

```php
$this->app->singleton(\App\Services\Ai\AiClient::class);
```

- [ ] **Step 6: Seed the new `site_settings` keys**

Edit `database/seeders/SiteSettingsSeeder.php`. Append to the `$defaults` array (or equivalent structure):

```php
['key' => 'stage1_score_floor',              'value' => '0.2',                         'type' => 'string', 'description' => 'Minimum cosine similarity for a job to enter the feed.'],
['key' => 'cost_ceiling_per_user_per_day_usd','value' => '5.00',                       'type' => 'string', 'description' => 'Per-user daily AI spend ceiling (USD).'],
['key' => 'openai_embeddings_model',         'value' => 'text-embedding-3-small',      'type' => 'string', 'description' => 'OpenAI model used for both resume and job embeddings.'],
['key' => 'anthropic_parse_model',           'value' => 'claude-sonnet-4-6',           'type' => 'string', 'description' => 'Anthropic model used by AiClient::parseResume.'],
```

(If the seeder already iterates a declarative array, add these rows there; if it uses explicit `SiteSetting::updateOrCreate` calls, add four matching calls.)

- [ ] **Step 7: Run the migration + reseed**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan migrate --no-interaction
/c/Users/david/.config/herd/bin/php84/php.exe artisan db:seed --class=SiteSettingsSeeder --no-interaction
```

Expected: `ai_usage_events` table created; four new `site_settings` rows present. Verify:

```bash
docker exec throughline-postgres psql -U throughline -d throughline -c "SELECT key, value FROM site_settings WHERE key IN ('stage1_score_floor','cost_ceiling_per_user_per_day_usd','openai_embeddings_model','anthropic_parse_model');"
```

- [ ] **Step 8: Format + commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_04_21_100100_create_ai_usage_events_table.php app/Models/AiUsageEvent.php app/Services/Ai/AiClient.php app/Services/Ai/Exceptions database/seeders/SiteSettingsSeeder.php app/Providers/AppServiceProvider.php
git commit -m "$(cat <<'EOF'
feat: AiClient scaffolding + ai_usage_events table + typed exceptions

Creates the ai_usage_events audit table with indexes for per-user cost
aggregation and admin charts. Adds the AiClient service surface
(forUser/embed/parseResume), singleton-bound, with bodies deferred to
Task 3. Ships typed exceptions CostCeilingExceeded, ProviderRateLimited,
and ParseFailed so callers can branch on known failure modes without
sniffing messages. Seeds stage1_score_floor, cost_ceiling_per_user_per_day_usd,
openai_embeddings_model, anthropic_parse_model into site_settings.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: `AiClient` implementation + cost-ceiling + rate-limiter tests

**Files:**
- Modify: `composer.json` (add `openai-php/laravel` + `anthropic-sdk-php` — whatever the current packages are)
- Create: `config/services.php` (modify — add OpenAI + Anthropic entries)
- Modify: `app/Services/Ai/AiClient.php`
- Create: `tests/Feature/Ai/AiClientCostCeilingTest.php`
- Create: `tests/Feature/Ai/AiClientRateLimiterTest.php`
- Create: `tests/Feature/Ai/AiClientParseResumeTest.php`
- Create: `tests/Feature/Ai/AiClientEmbedTest.php`

- [ ] **Step 1: Install provider SDKs**

```bash
composer require openai-php/laravel anthropic-ai/sdk
```

Expected: both packages added; the OpenAI package may publish a config stub — accept defaults.

- [ ] **Step 2: Add services config**

Edit `config/services.php` — append inside the returned array:

```php
'openai' => [
    'api_key' => env('OPENAI_API_KEY'),
    'organization' => env('OPENAI_ORGANIZATION'),
],

'anthropic' => [
    'api_key' => env('ANTHROPIC_API_KEY'),
],

'ai' => [
    'embedding_dimensions' => 1536,
    // Approx cost per 1K tokens — used for ai_usage_events.estimated_cost_usd
    'cost_per_1k_tokens' => [
        'text-embedding-3-small' => ['input' => 0.00002, 'output' => 0.0],
        'claude-sonnet-4-6'      => ['input' => 0.003,   'output' => 0.015],
    ],
    'rate_limit' => [
        'openai' => ['max' => 500, 'per_seconds' => 60],
        'anthropic' => ['max' => 50, 'per_seconds' => 60],
    ],
],
```

Add to `.env.example`:

```env
OPENAI_API_KEY=
OPENAI_ORGANIZATION=
ANTHROPIC_API_KEY=
```

- [ ] **Step 3: Write the first failing test — cost ceiling**

`tests/Feature/Ai/AiClientCostCeilingTest.php`:

```php
<?php

namespace Tests\Feature\Ai;

use App\Models\AiUsageEvent;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\CostCeilingExceeded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiClientCostCeilingTest extends TestCase
{
    use RefreshDatabase;

    public function test_throws_when_user_exceeds_daily_ceiling(): void
    {
        $user = User::factory()->create();

        // Pre-load today's ai_usage_events to simulate prior spend at the ceiling.
        AiUsageEvent::create([
            'user_id' => $user->id,
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'operation' => 'embed',
            'input_tokens' => 100_000_000,
            'output_tokens' => 0,
            'estimated_cost_usd' => 5.01, // above default ceiling of 5.00
            'context_type' => 'resume',
            'context_id' => '1',
            'created_at' => now(),
        ]);

        $this->expectException(CostCeilingExceeded::class);
        app(AiClient::class)->forUser($user)->embed('hello world', 'resume');
    }

    public function test_system_calls_bypass_ceiling(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'data' => [['embedding' => array_fill(0, 1536, 0.0)]],
                'usage' => ['prompt_tokens' => 3, 'total_tokens' => 3],
            ]),
        ]);

        $vector = app(AiClient::class)->forUser(null)->embed('system text', 'job');
        $this->assertCount(1536, $vector);
    }
}
```

- [ ] **Step 4: Run the test to verify it fails**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=AiClientCostCeilingTest
```

Expected: FAIL — `AiClient::embed` still throws the Task-2 `LogicException`.

- [ ] **Step 5: Write the rate-limiter test**

`tests/Feature/Ai/AiClientRateLimiterTest.php`:

```php
<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\ProviderRateLimited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiClientRateLimiterTest extends TestCase
{
    use RefreshDatabase;

    public function test_translates_provider_429_into_typed_exception(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(
                ['error' => ['message' => 'Rate limit reached']],
                429,
                ['retry-after' => '12'],
            ),
        ]);

        $this->expectException(ProviderRateLimited::class);

        try {
            app(AiClient::class)->forUser(null)->embed('x', 'job');
        } catch (ProviderRateLimited $e) {
            $this->assertSame('openai', $e->provider);
            $this->assertSame(12, $e->retryAfterSeconds);
            throw $e;
        }
    }

    public function test_blocks_when_local_token_bucket_empty(): void
    {
        // Drain the bucket preemptively.
        Cache::put('ai_ratelimit:openai', 0, now()->addSeconds(30));

        $this->expectException(ProviderRateLimited::class);
        app(AiClient::class)->forUser(null)->embed('x', 'job');
    }
}
```

- [ ] **Step 6: Write the parseResume test**

`tests/Feature/Ai/AiClientParseResumeTest.php`:

```php
<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\ParseFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiClientParseResumeTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_structured_json_on_success(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'text' => 'Plain-text summary.',
                        'structured' => [
                            'name' => 'Jane Doe',
                            'titles' => ['Staff Engineer'],
                            'companies' => ['Acme'],
                            'skills' => ['PHP', 'Postgres'],
                            'years_experience' => 12,
                        ],
                    ]),
                ]],
                'usage' => ['input_tokens' => 500, 'output_tokens' => 200],
            ]),
        ]);

        $user = User::factory()->create();
        $result = app(AiClient::class)->forUser($user)->parseResume('dummy raw text');

        $this->assertSame('Plain-text summary.', $result['text']);
        $this->assertSame('Jane Doe', $result['structured']['name']);
    }

    public function test_throws_parsefailed_on_unparseable_response(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => 'not json at all',
                ]],
                'usage' => ['input_tokens' => 500, 'output_tokens' => 20],
            ]),
        ]);

        $user = User::factory()->create();
        $this->expectException(ParseFailed::class);
        app(AiClient::class)->forUser($user)->parseResume('dummy');
    }
}
```

- [ ] **Step 7: Write the embed test**

`tests/Feature/Ai/AiClientEmbedTest.php`:

```php
<?php

namespace Tests\Feature\Ai;

use App\Models\AiUsageEvent;
use App\Models\User;
use App\Services\Ai\AiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiClientEmbedTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_vector_and_writes_usage_row(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'data' => [['embedding' => array_fill(0, 1536, 0.5)]],
                'usage' => ['prompt_tokens' => 42, 'total_tokens' => 42],
            ]),
        ]);

        $user = User::factory()->create();
        $vector = app(AiClient::class)->forUser($user)->embed('resume text', 'resume');

        $this->assertCount(1536, $vector);
        $this->assertDatabaseHas('ai_usage_events', [
            'user_id' => $user->id,
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'operation' => 'embed',
            'input_tokens' => 42,
            'context_type' => 'resume',
        ]);
    }

    public function test_null_user_writes_system_row(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'data' => [['embedding' => array_fill(0, 1536, 0.1)]],
                'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
            ]),
        ]);

        app(AiClient::class)->forUser(null)->embed('job desc', 'job');

        $this->assertDatabaseHas('ai_usage_events', [
            'user_id' => null,
            'context_type' => 'job',
        ]);
    }
}
```

- [ ] **Step 8: Run all four tests to confirm they fail**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=AiClient
```

Expected: FAIL across the board.

- [ ] **Step 9: Implement the real `AiClient`**

Overwrite `app/Services/Ai/AiClient.php`:

```php
<?php

namespace App\Services\Ai;

use App\Models\AiUsageEvent;
use App\Models\User;
use App\Services\Ai\Exceptions\CostCeilingExceeded;
use App\Services\Ai\Exceptions\ParseFailed;
use App\Services\Ai\Exceptions\ProviderRateLimited;
use App\Services\SiteSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AiClient
{
    protected ?User $actingUser = null;

    public function __construct(protected SiteSettings $settings)
    {
    }

    public function forUser(?User $user): self
    {
        $clone = clone $this;
        $clone->actingUser = $user;

        return $clone;
    }

    public function embed(string $text, string $context): array
    {
        $this->enforceCostCeiling();
        $this->consumeRateBucket('openai');

        $model = $this->settings->get('openai_embeddings_model', 'text-embedding-3-small');

        $response = Http::withToken(config('services.openai.api_key'))
            ->acceptJson()
            ->asJson()
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => $model,
                'input' => $text,
            ]);

        if ($response->status() === 429) {
            throw new ProviderRateLimited('openai', (int) ($response->header('retry-after') ?: 30));
        }

        $response->throw();

        $data = $response->json();
        $vector = $data['data'][0]['embedding'] ?? [];
        $inputTokens = (int) ($data['usage']['prompt_tokens'] ?? 0);

        $this->recordUsage('openai', $model, 'embed', $inputTokens, 0, $context);

        return $vector;
    }

    public function parseResume(string $rawText): array
    {
        $this->enforceCostCeiling();
        $this->consumeRateBucket('anthropic');

        $model = $this->settings->get('anthropic_parse_model', 'claude-sonnet-4-6');

        $prompt = <<<PROMPT
You extract structured data from resumes. Return ONLY compact JSON matching:
{"text": "<plain-text summary>", "structured": {"name": "...", "titles": [...], "companies": [...], "skills": [...], "years_experience": <int|null>}}

Resume follows:
---
{$rawText}
PROMPT;

        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.api_key'),
            'anthropic-version' => '2023-06-01',
        ])->acceptJson()->asJson()->post('https://api.anthropic.com/v1/messages', [
            'model' => $model,
            'max_tokens' => 2048,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);

        if ($response->status() === 429) {
            throw new ProviderRateLimited('anthropic', (int) ($response->header('retry-after') ?: 30));
        }

        $response->throw();

        $data = $response->json();
        $raw = $data['content'][0]['text'] ?? '';
        $parsed = json_decode($raw, true);
        if (!is_array($parsed) || !isset($parsed['text'], $parsed['structured'])) {
            throw new ParseFailed('Claude response did not contain text + structured keys', $raw);
        }

        $inputTokens = (int) ($data['usage']['input_tokens'] ?? 0);
        $outputTokens = (int) ($data['usage']['output_tokens'] ?? 0);

        $this->recordUsage('anthropic', $model, 'parse', $inputTokens, $outputTokens, 'resume');

        return $parsed;
    }

    protected function enforceCostCeiling(): void
    {
        if (!$this->actingUser) {
            return;
        }

        $ceiling = (float) $this->settings->get('cost_ceiling_per_user_per_day_usd', '5.00');
        $spent = (float) AiUsageEvent::where('user_id', $this->actingUser->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('estimated_cost_usd');

        if ($spent >= $ceiling) {
            throw new CostCeilingExceeded($this->actingUser->id, $spent, $ceiling);
        }
    }

    protected function consumeRateBucket(string $provider): void
    {
        $config = config("services.ai.rate_limit.{$provider}");
        $key = "ai_ratelimit:{$provider}";
        $current = Cache::get($key);

        if ($current === null) {
            Cache::put($key, $config['max'] - 1, now()->addSeconds($config['per_seconds']));
            return;
        }

        if ($current <= 0) {
            throw new ProviderRateLimited($provider, $config['per_seconds']);
        }

        Cache::decrement($key);
    }

    protected function recordUsage(
        string $provider,
        string $model,
        string $operation,
        int $inputTokens,
        int $outputTokens,
        ?string $contextType,
    ): void {
        $rates = config("services.ai.cost_per_1k_tokens.{$model}", ['input' => 0, 'output' => 0]);
        $cost = ($inputTokens / 1000) * $rates['input'] + ($outputTokens / 1000) * $rates['output'];

        AiUsageEvent::create([
            'user_id' => $this->actingUser?->id,
            'provider' => $provider,
            'model' => $model,
            'operation' => $operation,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'estimated_cost_usd' => $cost,
            'context_type' => $contextType,
            'context_id' => null,
            'created_at' => now(),
        ]);
    }
}
```

- [ ] **Step 10: Run the AiClient tests to confirm they pass**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=AiClient
```

Expected: all 8 tests (2 ceiling + 2 rate + 2 parse + 2 embed) pass.

- [ ] **Step 11: Run the full suite to confirm no regressions**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --compact
```

Expected: all tests pass, including Plan 1's existing suite.

- [ ] **Step 12: Format + commit**

```bash
vendor/bin/pint --dirty
git add composer.json composer.lock config/services.php app/Services/Ai/AiClient.php tests/Feature/Ai .env.example
git commit -m "$(cat <<'EOF'
feat: AiClient implementation with cost ceiling + rate limiter + usage audit

Wires AiClient::embed and AiClient::parseResume to OpenAI and
Anthropic. Per-user daily cost ceiling reads SiteSettings and
aggregates ai_usage_events; system calls (forUser(null)) bypass
the ceiling. Per-provider Redis token bucket throttles outbound
calls and translates 429 responses into typed
ProviderRateLimited exceptions. Every successful call writes one
ai_usage_events row with estimated cost derived from the provider's
token counts times config/services.php rate tables.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Resumes table, model, and parse/embed queued jobs

**Files:**
- Create: `database/migrations/2026_04_21_100200_create_resumes_table.php`
- Create: `app/Models/Resume.php`
- Create: `database/factories/ResumeFactory.php`
- Modify: `app/Models/User.php` (add `resumes()` HasMany relation)
- Create: `app/Jobs/ParseResume.php`
- Create: `app/Jobs/EmbedResume.php`
- Create: `app/Jobs/RecomputeStage1ForResume.php` (stub — real body lands in Task 12)
- Create: `tests/Feature/Resumes/ResumePipelineTest.php`

- [ ] **Step 1: Install text-extraction packages**

```bash
composer require spatie/pdf-to-text phpoffice/phpword
```

Expected: both packages added. `spatie/pdf-to-text` requires Poppler's `pdftotext` binary on PATH for runtime; on Windows install via `winget install poppler` or equivalent. The tests use mocked extraction so CI doesn't require the binary.

- [ ] **Step 2: Write the migration**

`database/migrations/2026_04_21_100200_create_resumes_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('resumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('source_file_path')->nullable();
            $table->string('source_mime', 64)->nullable();
            $table->text('parsed_text')->nullable();
            $table->jsonb('structured_json')->nullable();
            $table->string('parse_status', 24)->default('pending');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'parse_status']);
        });

        DB::statement('ALTER TABLE resumes ADD COLUMN embedding vector(1536)');
        DB::statement('CREATE UNIQUE INDEX resumes_user_id_default_unique ON resumes (user_id) WHERE is_default = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('resumes');
    }
};
```

- [ ] **Step 3: Write the `Resume` model**

`app/Models/Resume.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resume extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'source_file_path',
        'source_mime',
        'parsed_text',
        'structured_json',
        'parse_status',
        'is_default',
    ];

    protected $casts = [
        'structured_json' => 'array',
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isReady(): bool
    {
        return $this->parse_status === 'parsed' && ! is_null($this->embedding_raw());
    }

    public function embedding_raw(): ?string
    {
        // Raw pgvector column — Eloquent can't cast vector natively, so we hit the DB.
        $row = \DB::table('resumes')->where('id', $this->id)->value('embedding');
        return $row;
    }
}
```

- [ ] **Step 4: Write the factory**

`database/factories/ResumeFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Resume;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ResumeFactory extends Factory
{
    protected $model = Resume::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->jobTitle(),
            'source_file_path' => null,
            'source_mime' => 'application/pdf',
            'parsed_text' => null,
            'structured_json' => null,
            'parse_status' => 'pending',
            'is_default' => false,
        ];
    }

    public function parsed(): self
    {
        return $this->state(['parse_status' => 'parsed', 'parsed_text' => 'Parsed resume text.']);
    }

    public function default(): self
    {
        return $this->state(['is_default' => true]);
    }
}
```

- [ ] **Step 5: Add the `resumes()` relation to `User`**

Edit `app/Models/User.php` — add inside the class body:

```php
public function resumes(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(Resume::class);
}
```

- [ ] **Step 6: Write the `ParseResume` job**

`app/Jobs/ParseResume.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Resume;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\ParseFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use PhpOffice\PhpWord\IOFactory as WordIO;
use Spatie\PdfToText\Pdf as PdfToText;

class ParseResume implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $resumeId)
    {
    }

    public function handle(AiClient $ai): void
    {
        $resume = Resume::findOrFail($this->resumeId);
        if ($resume->parse_status === 'parsed') {
            return; // idempotent
        }

        $rawText = $this->extractRawText($resume);
        $resume->parsed_text = $rawText;
        $resume->save();

        try {
            $result = $ai->forUser($resume->user)->parseResume($rawText);
        } catch (ParseFailed $e) {
            $resume->parse_status = 'parse_failed';
            $resume->save();
            return;
        }

        $resume->parsed_text = $result['text'];
        $resume->structured_json = $result['structured'];
        $resume->parse_status = 'parsed';
        $resume->save();

        EmbedResume::dispatch($resume->id);
    }

    protected function extractRawText(Resume $resume): string
    {
        $diskPath = storage_path('app/' . $resume->source_file_path);
        return match ($resume->source_mime) {
            'application/pdf' => PdfToText::getText($diskPath),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->extractDocx($diskPath),
            default => file_get_contents($diskPath) ?: '',
        };
    }

    protected function extractDocx(string $path): string
    {
        $phpWord = WordIO::load($path);
        $text = '';
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $text .= $element->getText() . "\n";
                }
            }
        }
        return trim($text);
    }
}
```

- [ ] **Step 7: Write the `EmbedResume` job**

`app/Jobs/EmbedResume.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Resume;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\CostCeilingExceeded;
use App\Services\Ai\Exceptions\ProviderRateLimited;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class EmbedResume implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $resumeId)
    {
    }

    public function handle(AiClient $ai): void
    {
        $resume = Resume::findOrFail($this->resumeId);
        if ($resume->parse_status !== 'parsed') {
            return;
        }

        try {
            $vector = $ai->forUser($resume->user)->embed($resume->parsed_text, 'resume');
        } catch (CostCeilingExceeded $e) {
            $this->release(now()->diffInSeconds(now()->endOfDay()));
            return;
        } catch (ProviderRateLimited $e) {
            $this->release($e->retryAfterSeconds);
            return;
        }

        DB::statement('UPDATE resumes SET embedding = ?::vector WHERE id = ?', [
            '[' . implode(',', $vector) . ']',
            $resume->id,
        ]);

        RecomputeStage1ForResume::dispatch($resume->id);
    }
}
```

- [ ] **Step 8: Write the stub `RecomputeStage1ForResume` job**

`app/Jobs/RecomputeStage1ForResume.php`:

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecomputeStage1ForResume implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $resumeId)
    {
    }

    public function handle(): void
    {
        // Real body lands in Plan 2 Task 12 once job_matches and FanOutStage1Matches exist.
        // For now this job is dispatched by EmbedResume as a no-op so the wiring is in place.
    }
}
```

- [ ] **Step 9: Write the pipeline feature test**

`tests/Feature/Resumes/ResumePipelineTest.php`:

```php
<?php

namespace Tests\Feature\Resumes;

use App\Jobs\EmbedResume;
use App\Jobs\ParseResume;
use App\Jobs\RecomputeStage1ForResume;
use App\Models\Resume;
use App\Models\User;
use App\Services\Ai\AiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ResumePipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_parse_resume_success_path_dispatches_embed(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create([
            'source_file_path' => 'stub.txt',
            'source_mime' => 'text/plain',
        ]);
        \Storage::disk('local')->put('stub.txt', 'raw text');

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->with(Mockery::type(User::class))->andReturnSelf();
        $ai->shouldReceive('parseResume')->andReturn([
            'text' => 'Parsed text.',
            'structured' => ['skills' => ['PHP']],
        ]);
        $this->app->instance(AiClient::class, $ai);

        (new ParseResume($resume->id))->handle($ai);

        $resume->refresh();
        $this->assertSame('parsed', $resume->parse_status);
        $this->assertSame('Parsed text.', $resume->parsed_text);
        Queue::assertPushed(EmbedResume::class, fn ($job) => $job->resumeId === $resume->id);
    }

    public function test_parse_failure_sets_parse_failed_and_preserves_raw(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create([
            'source_file_path' => 'stub.txt',
            'source_mime' => 'text/plain',
        ]);
        \Storage::disk('local')->put('stub.txt', 'unparseable');

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('parseResume')->andThrow(
            new \App\Services\Ai\Exceptions\ParseFailed('bad json', 'raw')
        );
        $this->app->instance(AiClient::class, $ai);

        (new ParseResume($resume->id))->handle($ai);

        $resume->refresh();
        $this->assertSame('parse_failed', $resume->parse_status);
        $this->assertSame('unparseable', $resume->parsed_text);
    }

    public function test_embed_resume_writes_vector_and_dispatches_recompute(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->parsed()->create();

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('embed')->with(Mockery::any(), 'resume')->andReturn(array_fill(0, 1536, 0.25));
        $this->app->instance(AiClient::class, $ai);

        (new EmbedResume($resume->id))->handle($ai);

        $vector = \DB::table('resumes')->where('id', $resume->id)->value('embedding');
        $this->assertNotNull($vector);
        Queue::assertPushed(RecomputeStage1ForResume::class);
    }
}
```

- [ ] **Step 10: Run migration + test**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan migrate --no-interaction
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=ResumePipelineTest
```

Expected: tests pass.

- [ ] **Step 11: Format + commit**

```bash
vendor/bin/pint --dirty
git add composer.json composer.lock database/migrations/2026_04_21_100200_create_resumes_table.php app/Models/Resume.php app/Models/User.php database/factories/ResumeFactory.php app/Jobs tests/Feature/Resumes
git commit -m "$(cat <<'EOF'
feat: resumes table + parse/embed/recompute queued jobs

Resumes schema includes pgvector(1536) embedding column, partial
unique index enforcing one default per user, structured_json jsonb,
and a parse_status state machine (pending → parsed / parse_failed).
Three queued jobs wired: ParseResume extracts raw text (PDF via
Poppler, DOCX via phpoffice/phpword), calls AiClient::parseResume,
writes structured JSON; EmbedResume calls AiClient::embed and
persists the vector via raw DB::statement; RecomputeStage1ForResume
stubbed as a no-op until Plan 2 Task 12 wires real stage-1 fan-out.
Cost-ceiling and rate-limit exceptions release the embed job with
a delay instead of failing.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Resume upload controller + manager UI

**Files:**
- Create: `app/Http/Controllers/Resumes/ResumeController.php`
- Create: `app/Http/Requests/Resumes/StoreResumeRequest.php`
- Create: `app/Http/Requests/Resumes/UpdateResumeRequest.php`
- Modify: `routes/web.php`
- Create: `resources/js/pages/resumes/Index.vue`
- Create: `resources/js/pages/resumes/Show.vue`
- Create: `resources/js/components/resumes/UploadDialog.vue`
- Create: `resources/js/components/resumes/ResumeListItem.vue`
- Modify: `resources/js/layouts/AppLayout.vue` (add a "Resumes" nav link)
- Create: `tests/Feature/Resumes/ResumeControllerTest.php`

- [ ] **Step 1: Write the form-request validators**

`app/Http/Requests/Resumes/StoreResumeRequest.php`:

```php
<?php

namespace App\Http\Requests\Resumes;

use Illuminate\Foundation\Http\FormRequest;

class StoreResumeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'file' => ['required', 'file', 'mimetypes:application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'max:10240'],
        ];
    }
}
```

`app/Http/Requests/Resumes/UpdateResumeRequest.php`:

```php
<?php

namespace App\Http\Requests\Resumes;

use Illuminate\Foundation\Http\FormRequest;

class UpdateResumeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && (int) $this->route('resume')->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
        ];
    }
}
```

- [ ] **Step 2: Write the controller**

`app/Http/Controllers/Resumes/ResumeController.php`:

```php
<?php

namespace App\Http\Controllers\Resumes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Resumes\StoreResumeRequest;
use App\Http\Requests\Resumes\UpdateResumeRequest;
use App\Jobs\ParseResume;
use App\Models\Resume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ResumeController extends Controller
{
    public function index(): Response
    {
        $resumes = auth()->user()->resumes()
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'parse_status', 'is_default', 'created_at']);

        return Inertia::render('resumes/Index', [
            'resumes' => $resumes,
        ]);
    }

    public function show(Resume $resume): Response
    {
        abort_unless($resume->user_id === auth()->id(), 403);

        $hasEmbedding = DB::table('resumes')->where('id', $resume->id)->whereNotNull('embedding')->exists();

        return Inertia::render('resumes/Show', [
            'resume' => [
                'id' => $resume->id,
                'name' => $resume->name,
                'parse_status' => $resume->parse_status,
                'is_default' => $resume->is_default,
                'has_embedding' => $hasEmbedding,
                'parsed_text' => $resume->parsed_text,
                'structured_json' => $resume->structured_json,
                'created_at' => $resume->created_at,
            ],
        ]);
    }

    public function store(StoreResumeRequest $request): RedirectResponse
    {
        $file = $request->file('file');
        $path = $file->store('resumes/' . auth()->id(), 'local');

        $resume = auth()->user()->resumes()->create([
            'name' => $request->string('name'),
            'source_file_path' => $path,
            'source_mime' => $file->getMimeType(),
            'parse_status' => 'pending',
            'is_default' => auth()->user()->resumes()->count() === 0,
        ]);

        ParseResume::dispatch($resume->id);

        return redirect()->route('resumes.show', $resume)->with('status', 'Resume uploaded. Parsing…');
    }

    public function update(UpdateResumeRequest $request, Resume $resume): RedirectResponse
    {
        $resume->update(['name' => $request->string('name')]);

        return back()->with('status', 'Resume renamed.');
    }

    public function destroy(Resume $resume): RedirectResponse
    {
        abort_unless($resume->user_id === auth()->id(), 403);

        if ($resume->source_file_path) {
            Storage::disk('local')->delete($resume->source_file_path);
        }
        $resume->delete();

        return redirect()->route('resumes.index')->with('status', 'Resume deleted.');
    }

    public function setDefault(Resume $resume): RedirectResponse
    {
        abort_unless($resume->user_id === auth()->id(), 403);

        DB::transaction(function () use ($resume) {
            auth()->user()->resumes()->where('id', '!=', $resume->id)->update(['is_default' => false]);
            $resume->update(['is_default' => true]);
        });

        return back()->with('status', 'Default resume updated.');
    }

    public function retry(Resume $resume): RedirectResponse
    {
        abort_unless($resume->user_id === auth()->id(), 403);
        $resume->update(['parse_status' => 'pending']);
        ParseResume::dispatch($resume->id);

        return back()->with('status', 'Parse retry queued.');
    }
}
```

- [ ] **Step 3: Wire routes**

Edit `routes/web.php` — add inside the `auth`-middleware group:

```php
use App\Http\Controllers\Resumes\ResumeController;

Route::middleware('auth')->group(function () {
    Route::get('/resumes', [ResumeController::class, 'index'])->name('resumes.index');
    Route::post('/resumes', [ResumeController::class, 'store'])->name('resumes.store');
    Route::get('/resumes/{resume}', [ResumeController::class, 'show'])->name('resumes.show');
    Route::patch('/resumes/{resume}', [ResumeController::class, 'update'])->name('resumes.update');
    Route::delete('/resumes/{resume}', [ResumeController::class, 'destroy'])->name('resumes.destroy');
    Route::post('/resumes/{resume}/default', [ResumeController::class, 'setDefault'])->name('resumes.default');
    Route::post('/resumes/{resume}/retry', [ResumeController::class, 'retry'])->name('resumes.retry');
});
```

- [ ] **Step 4: Write the feature test**

`tests/Feature/Resumes/ResumeControllerTest.php`:

```php
<?php

namespace Tests\Feature\Resumes;

use App\Jobs\ParseResume;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResumeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_dispatches_parse_and_marks_first_as_default(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

        $this->actingAs($user)
            ->post(route('resumes.store'), ['name' => 'Staff frontend', 'file' => $file])
            ->assertRedirect();

        $resume = Resume::first();
        $this->assertTrue($resume->is_default);
        Queue::assertPushed(ParseResume::class);
    }

    public function test_second_resume_does_not_become_default(): void
    {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        Resume::factory()->for($user)->default()->create();
        $file = UploadedFile::fake()->create('other.pdf', 100, 'application/pdf');

        $this->actingAs($user)->post(route('resumes.store'), ['name' => 'Eng manager', 'file' => $file]);

        $this->assertSame(1, Resume::where('is_default', true)->count());
    }

    public function test_set_default_flips_exclusivity(): void
    {
        $user = User::factory()->create();
        $current = Resume::factory()->for($user)->default()->create();
        $next = Resume::factory()->for($user)->create();

        $this->actingAs($user)->post(route('resumes.default', $next))->assertRedirect();

        $this->assertTrue($next->fresh()->is_default);
        $this->assertFalse($current->fresh()->is_default);
    }

    public function test_show_forbids_other_users_resumes(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $resume = Resume::factory()->for($owner)->create();

        $this->actingAs($stranger)->get(route('resumes.show', $resume))->assertForbidden();
    }

    public function test_destroy_removes_file_and_row(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        Storage::disk('local')->put('resumes/stub.pdf', 'x');
        $resume = Resume::factory()->for($user)->create(['source_file_path' => 'resumes/stub.pdf']);

        $this->actingAs($user)->delete(route('resumes.destroy', $resume))->assertRedirect();

        $this->assertDatabaseMissing('resumes', ['id' => $resume->id]);
        Storage::disk('local')->assertMissing('resumes/stub.pdf');
    }
}
```

- [ ] **Step 5: Write `resources/js/pages/resumes/Index.vue`**

```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import UploadDialog from '@/components/resumes/UploadDialog.vue';
import ResumeListItem from '@/components/resumes/ResumeListItem.vue';
import { ref } from 'vue';

const props = defineProps<{
  resumes: Array<{ id: number; name: string; parse_status: string; is_default: boolean; created_at: string }>;
}>();

const showUpload = ref(false);

function onUploaded() {
  showUpload.value = false;
  router.reload({ only: ['resumes'] });
}
</script>

<template>
  <Head title="Resumes" />
  <AppLayout>
    <div class="mx-auto max-w-3xl px-6 py-10">
      <header class="mb-8 flex items-end justify-between">
        <div>
          <h1 class="font-display text-4xl text-ink">Your resumes</h1>
          <p class="mt-1 text-ink-3">Different career arcs deserve different resumes.</p>
        </div>
        <button type="button" class="rounded-full bg-oxblood px-5 py-2 text-sm font-medium text-bone" @click="showUpload = true">
          Upload resume
        </button>
      </header>

      <ul v-if="resumes.length" class="divide-y divide-hairline">
        <ResumeListItem v-for="resume in resumes" :key="resume.id" :resume="resume" />
      </ul>

      <div v-else class="rounded-lg border border-hairline bg-bone-2 px-6 py-12 text-center">
        <p class="text-ink-2">No resumes yet. Upload one to start building search profiles.</p>
      </div>

      <UploadDialog v-if="showUpload" @uploaded="onUploaded" @close="showUpload = false" />
    </div>
  </AppLayout>
</template>
```

- [ ] **Step 6: Write `resources/js/pages/resumes/Show.vue`**

```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';

const props = defineProps<{
  resume: {
    id: number;
    name: string;
    parse_status: string;
    is_default: boolean;
    has_embedding: boolean;
    parsed_text: string | null;
    structured_json: Record<string, any> | null;
    created_at: string;
  };
}>();

let pollHandle: number | undefined;

onMounted(() => {
  if (props.resume.parse_status === 'pending' || !props.resume.has_embedding) {
    pollHandle = window.setInterval(() => router.reload({ only: ['resume'] }), 3000);
  }
});

onUnmounted(() => {
  if (pollHandle) clearInterval(pollHandle);
});

function rename() {
  const name = window.prompt('Rename resume:', props.resume.name);
  if (name && name.trim()) {
    router.patch(route('resumes.update', props.resume.id), { name });
  }
}

function setDefault() {
  router.post(route('resumes.default', props.resume.id));
}

function retry() {
  router.post(route('resumes.retry', props.resume.id));
}

function destroy() {
  if (window.confirm('Delete this resume? This cannot be undone.')) {
    router.delete(route('resumes.destroy', props.resume.id));
  }
}
</script>

<template>
  <Head :title="resume.name" />
  <AppLayout>
    <div class="mx-auto max-w-3xl px-6 py-10">
      <Link :href="route('resumes.index')" class="text-sm text-ink-3 hover:text-ink">&larr; All resumes</Link>
      <header class="mt-4 flex items-start justify-between">
        <h1 class="font-display text-4xl text-ink">{{ resume.name }}</h1>
        <span v-if="resume.is_default" class="rounded-full border border-oxblood px-3 py-1 text-xs font-medium text-oxblood">Default</span>
      </header>

      <section class="mt-8 rounded-lg border border-hairline bg-bone-2 px-6 py-5">
        <p class="text-sm text-ink-2">
          <span v-if="resume.parse_status === 'pending'">Parsing your resume. This usually takes under a minute…</span>
          <span v-else-if="resume.parse_status === 'parse_failed'">Parse failed. You can paste the text manually or retry.</span>
          <span v-else-if="!resume.has_embedding">Parsed. Generating embedding…</span>
          <span v-else>Ready to match against jobs.</span>
        </p>
        <div class="mt-3 flex flex-wrap gap-2">
          <button v-if="!resume.is_default" class="rounded border border-ink-3 px-3 py-1 text-sm" @click="setDefault">Set as default</button>
          <button class="rounded border border-ink-3 px-3 py-1 text-sm" @click="rename">Rename</button>
          <button v-if="resume.parse_status === 'parse_failed'" class="rounded border border-oxblood px-3 py-1 text-sm text-oxblood" @click="retry">Retry parse</button>
          <button class="rounded border border-ink-3 px-3 py-1 text-sm text-ink-3" @click="destroy">Delete</button>
        </div>
      </section>

      <section v-if="resume.structured_json" class="mt-8">
        <h2 class="font-display text-xl text-ink">Extracted highlights</h2>
        <pre class="mt-3 overflow-x-auto rounded border border-hairline bg-bone-2 p-4 text-xs text-ink-2">{{ JSON.stringify(resume.structured_json, null, 2) }}</pre>
      </section>

      <section v-if="resume.parsed_text" class="mt-8">
        <h2 class="font-display text-xl text-ink">Plain-text content</h2>
        <pre class="mt-3 max-h-96 overflow-auto rounded border border-hairline bg-bone-2 p-4 text-xs text-ink-2 whitespace-pre-wrap">{{ resume.parsed_text }}</pre>
      </section>
    </div>
  </AppLayout>
</template>
```

- [ ] **Step 7: Write `resources/js/components/resumes/UploadDialog.vue`**

```vue
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const emit = defineEmits<{ uploaded: []; close: [] }>();

const name = ref('');
const file = ref<File | null>(null);
const uploading = ref(false);
const error = ref<string | null>(null);

function onFile(event: Event) {
  const target = event.target as HTMLInputElement;
  file.value = target.files?.[0] ?? null;
}

function submit() {
  if (!file.value || !name.value.trim()) {
    error.value = 'Name and file are required.';
    return;
  }

  const form = new FormData();
  form.append('name', name.value.trim());
  form.append('file', file.value);

  uploading.value = true;
  router.post(route('resumes.store'), form, {
    forceFormData: true,
    onSuccess: () => emit('uploaded'),
    onError: (errors) => {
      error.value = Object.values(errors).flat().join(' ');
      uploading.value = false;
    },
    onFinish: () => { uploading.value = false; },
  });
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 p-4" @click.self="emit('close')">
    <div class="w-full max-w-md rounded-lg bg-bone p-6 shadow-lg">
      <h2 class="font-display text-2xl text-ink">Upload resume</h2>
      <p class="mt-1 text-sm text-ink-3">PDF or DOCX, up to 10 MB.</p>

      <form class="mt-5 space-y-4" @submit.prevent="submit">
        <label class="block">
          <span class="text-sm text-ink-2">Name (e.g. Staff frontend)</span>
          <input v-model="name" type="text" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" />
        </label>
        <label class="block">
          <span class="text-sm text-ink-2">File</span>
          <input type="file" accept=".pdf,.docx" class="mt-1 w-full text-sm" @change="onFile" />
        </label>
        <p v-if="error" class="text-sm text-oxblood">{{ error }}</p>
        <div class="flex justify-end gap-2">
          <button type="button" class="rounded px-4 py-2 text-sm text-ink-3" @click="emit('close')">Cancel</button>
          <button type="submit" class="rounded-full bg-oxblood px-5 py-2 text-sm font-medium text-bone disabled:opacity-50" :disabled="uploading">
            {{ uploading ? 'Uploading…' : 'Upload' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
```

- [ ] **Step 8: Write `resources/js/components/resumes/ResumeListItem.vue`**

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
  resume: { id: number; name: string; parse_status: string; is_default: boolean; created_at: string };
}>();
</script>

<template>
  <li class="flex items-center justify-between py-4">
    <div>
      <Link :href="route('resumes.show', resume.id)" class="font-display text-lg text-ink hover:text-oxblood">
        {{ resume.name }}
      </Link>
      <p class="text-xs text-ink-3">
        {{ resume.parse_status === 'parsed' ? 'Parsed' : resume.parse_status === 'parse_failed' ? 'Parse failed' : 'Parsing…' }}
      </p>
    </div>
    <span v-if="resume.is_default" class="rounded-full border border-oxblood px-3 py-1 text-xs font-medium text-oxblood">
      Default
    </span>
  </li>
</template>
```

- [ ] **Step 9: Add the Resumes nav link in `AppLayout.vue`**

Open `resources/js/layouts/AppLayout.vue`. Inside the existing nav section, after the Dashboard link, add:

```vue
<Link :href="route('resumes.index')" class="text-sm text-ink-2 hover:text-ink">Resumes</Link>
```

- [ ] **Step 10: Run tests**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=ResumeControllerTest
```

Expected: all tests pass.

- [ ] **Step 11: Smoke the UI**

```bash
composer run dev
```

Log in, navigate to `/resumes`, upload a sample PDF, watch the detail page poll until `Ready to match against jobs.`. Requires `OPENAI_API_KEY` + `ANTHROPIC_API_KEY` in `.env` for the real parse/embed to complete. Without those keys, the pipeline will stall at parse — that's fine for this step; tests cover the happy path against fakes.

- [ ] **Step 12: Format + commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Resumes app/Http/Requests/Resumes routes/web.php resources/js/pages/resumes resources/js/components/resumes resources/js/layouts/AppLayout.vue tests/Feature/Resumes/ResumeControllerTest.php
git commit -m "$(cat <<'EOF'
feat: resume manager UI with upload, rename, set-default, delete, retry

ResumeController covers full CRUD plus setDefault (atomic via DB
transaction) and retry (re-queues ParseResume). Inertia pages under
resources/js/pages/resumes render the list, detail, and upload
dialog. The detail page polls /resumes/{id} until parse_status is
'parsed' and the embedding column is populated.

First-resume-per-user auto-marks is_default true; subsequent uploads
require an explicit set-default. Partial unique index from the
resumes migration guarantees server-side exclusivity.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Search profiles — schema, CRUD, UI

**Files:**
- Create: `database/migrations/2026_04_21_100300_create_search_profiles_table.php`
- Create: `app/Models/SearchProfile.php`
- Create: `database/factories/SearchProfileFactory.php`
- Create: `app/Http/Controllers/SearchProfiles/SearchProfileController.php`
- Create: `app/Http/Requests/SearchProfiles/StoreSearchProfileRequest.php`
- Create: `app/Http/Requests/SearchProfiles/UpdateSearchProfileRequest.php`
- Modify: `app/Models/User.php` (add `searchProfiles()` HasMany)
- Modify: `routes/web.php`
- Create: `resources/js/pages/search-profiles/Index.vue`
- Create: `resources/js/pages/search-profiles/Edit.vue`
- Create: `resources/js/components/search-profiles/ProfileForm.vue`
- Create: `tests/Feature/SearchProfiles/SearchProfileControllerTest.php`

- [ ] **Step 1: Write the migration**

`database/migrations/2026_04_21_100300_create_search_profiles_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('search_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resume_id')->nullable()->constrained('resumes')->nullOnDelete();
            $table->string('name', 120);
            $table->jsonb('role_keywords')->default('[]');
            $table->jsonb('locations')->default('[]');
            $table->string('remote_mode', 16)->default('any');
            $table->integer('comp_min')->nullable();
            $table->string('comp_currency', 8)->nullable();
            $table->jsonb('industries')->default('[]');
            $table->jsonb('seniority_levels')->default('[]');
            $table->jsonb('excluded_companies')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_profiles');
    }
};
```

- [ ] **Step 2: Write the model**

`app/Models/SearchProfile.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'resume_id',
        'name',
        'role_keywords',
        'locations',
        'remote_mode',
        'comp_min',
        'comp_currency',
        'industries',
        'seniority_levels',
        'excluded_companies',
        'is_active',
    ];

    protected $casts = [
        'role_keywords' => 'array',
        'locations' => 'array',
        'industries' => 'array',
        'seniority_levels' => 'array',
        'excluded_companies' => 'array',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resume(): BelongsTo
    {
        return $this->belongsTo(Resume::class);
    }
}
```

- [ ] **Step 3: Write the factory**

`database/factories/SearchProfileFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\SearchProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SearchProfileFactory extends Factory
{
    protected $model = SearchProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'resume_id' => null,
            'name' => fake()->jobTitle() . ' search',
            'role_keywords' => ['engineer'],
            'locations' => [],
            'remote_mode' => 'any',
            'comp_min' => null,
            'comp_currency' => null,
            'industries' => [],
            'seniority_levels' => [],
            'excluded_companies' => [],
            'is_active' => true,
        ];
    }
}
```

- [ ] **Step 4: Add the relation to `User`**

Edit `app/Models/User.php` — add alongside `resumes()`:

```php
public function searchProfiles(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(SearchProfile::class);
}
```

- [ ] **Step 5: Write the form requests**

`app/Http/Requests/SearchProfiles/StoreSearchProfileRequest.php`:

```php
<?php

namespace App\Http\Requests\SearchProfiles;

use App\Models\Resume;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSearchProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'resume_id' => [
                'nullable', 'integer',
                Rule::exists('resumes', 'id')->where('user_id', $this->user()->id),
            ],
            'role_keywords' => ['array'],
            'role_keywords.*' => ['string', 'max:60'],
            'locations' => ['array'],
            'locations.*' => ['string', 'max:80'],
            'remote_mode' => ['required', Rule::in(['any', 'remote_only', 'hybrid_ok', 'onsite'])],
            'comp_min' => ['nullable', 'integer', 'min:0'],
            'comp_currency' => ['nullable', 'string', 'size:3'],
            'industries' => ['array'],
            'industries.*' => ['string', 'max:60'],
            'seniority_levels' => ['array'],
            'seniority_levels.*' => ['string', 'max:40'],
            'excluded_companies' => ['array'],
            'excluded_companies.*' => ['string', 'max:120'],
            'is_active' => ['boolean'],
        ];
    }
}
```

`app/Http/Requests/SearchProfiles/UpdateSearchProfileRequest.php` — identical `rules()` body; `authorize()` checks `route('profile')->user_id === $this->user()->id`.

```php
<?php

namespace App\Http\Requests\SearchProfiles;

class UpdateSearchProfileRequest extends StoreSearchProfileRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && (int) $this->route('profile')->user_id === $this->user()->id;
    }
}
```

- [ ] **Step 6: Write the controller**

`app/Http/Controllers/SearchProfiles/SearchProfileController.php`:

```php
<?php

namespace App\Http\Controllers\SearchProfiles;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchProfiles\StoreSearchProfileRequest;
use App\Http\Requests\SearchProfiles\UpdateSearchProfileRequest;
use App\Models\SearchProfile;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SearchProfileController extends Controller
{
    public function index(): Response
    {
        $profiles = auth()->user()->searchProfiles()
            ->with('resume:id,name')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return Inertia::render('search-profiles/Index', [
            'profiles' => $profiles,
        ]);
    }

    public function create(): Response
    {
        return $this->renderForm(new SearchProfile([
            'remote_mode' => 'any',
            'is_active' => true,
        ]));
    }

    public function edit(SearchProfile $profile): Response
    {
        abort_unless($profile->user_id === auth()->id(), 403);
        return $this->renderForm($profile);
    }

    public function store(StoreSearchProfileRequest $request): RedirectResponse
    {
        $profile = auth()->user()->searchProfiles()->create($request->validated());
        return redirect()->route('search-profiles.index')->with('status', "Created '{$profile->name}'.");
    }

    public function update(UpdateSearchProfileRequest $request, SearchProfile $profile): RedirectResponse
    {
        $profile->update($request->validated());
        return redirect()->route('search-profiles.index')->with('status', 'Profile updated.');
    }

    public function destroy(SearchProfile $profile): RedirectResponse
    {
        abort_unless($profile->user_id === auth()->id(), 403);
        $profile->delete();
        return redirect()->route('search-profiles.index')->with('status', 'Profile deleted.');
    }

    protected function renderForm(SearchProfile $profile): Response
    {
        return Inertia::render('search-profiles/Edit', [
            'profile' => $profile,
            'resumes' => auth()->user()->resumes()->get(['id', 'name']),
        ]);
    }
}
```

- [ ] **Step 7: Add routes**

Edit `routes/web.php` — inside the existing auth group:

```php
use App\Http\Controllers\SearchProfiles\SearchProfileController;

Route::resource('search-profiles', SearchProfileController::class)
    ->parameters(['search-profiles' => 'profile'])
    ->except(['show']);
```

- [ ] **Step 8: Write the feature test**

`tests/Feature/SearchProfiles/SearchProfileControllerTest.php`:

```php
<?php

namespace Tests\Feature\SearchProfiles;

use App\Models\Resume;
use App\Models\SearchProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_profile_with_and_without_resume(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();

        $this->actingAs($user)->post(route('search-profiles.store'), [
            'name' => 'Staff frontend',
            'resume_id' => $resume->id,
            'role_keywords' => ['frontend', 'react'],
            'locations' => ['NYC'],
            'remote_mode' => 'remote_only',
            'comp_min' => 180000,
            'comp_currency' => 'USD',
            'industries' => ['fintech'],
            'seniority_levels' => ['staff', 'principal'],
            'excluded_companies' => ['BigCo'],
            'is_active' => true,
        ])->assertRedirect();

        $profile = SearchProfile::first();
        $this->assertSame($resume->id, $profile->resume_id);
        $this->assertSame(['frontend', 'react'], $profile->role_keywords);

        $this->actingAs($user)->post(route('search-profiles.store'), [
            'name' => 'Broad search',
            'resume_id' => null,
            'remote_mode' => 'any',
        ])->assertRedirect();

        $this->assertSame(2, SearchProfile::count());
    }

    public function test_deleting_resume_nulls_out_profile_resume_id(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $profile = SearchProfile::factory()->for($user)->create(['resume_id' => $resume->id]);

        $resume->delete();

        $this->assertNull($profile->fresh()->resume_id);
    }

    public function test_update_rejects_foreign_user(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $profile = SearchProfile::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->put(route('search-profiles.update', $profile), ['name' => 'Hacked', 'remote_mode' => 'any'])
            ->assertForbidden();
    }

    public function test_validation_rejects_unknown_remote_mode(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('search-profiles.store'), ['name' => 'x', 'remote_mode' => 'bogus'])
            ->assertSessionHasErrors('remote_mode');
    }
}
```

- [ ] **Step 9: Write `resources/js/components/search-profiles/ProfileForm.vue`**

```vue
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
  profile: {
    id?: number;
    name?: string;
    resume_id?: number | null;
    role_keywords?: string[];
    locations?: string[];
    remote_mode?: string;
    comp_min?: number | null;
    comp_currency?: string | null;
    industries?: string[];
    seniority_levels?: string[];
    excluded_companies?: string[];
    is_active?: boolean;
  };
  resumes: Array<{ id: number; name: string }>;
}>();

const form = useForm({
  name: props.profile.name ?? '',
  resume_id: props.profile.resume_id ?? null,
  role_keywords: props.profile.role_keywords ?? [],
  locations: props.profile.locations ?? [],
  remote_mode: props.profile.remote_mode ?? 'any',
  comp_min: props.profile.comp_min ?? null,
  comp_currency: props.profile.comp_currency ?? 'USD',
  industries: props.profile.industries ?? [],
  seniority_levels: props.profile.seniority_levels ?? [],
  excluded_companies: props.profile.excluded_companies ?? [],
  is_active: props.profile.is_active ?? true,
});

const isEditing = computed(() => !!props.profile.id);

function submit() {
  if (isEditing.value) {
    form.put(route('search-profiles.update', props.profile.id));
  } else {
    form.post(route('search-profiles.store'));
  }
}

function destroyProfile() {
  if (window.confirm('Delete this profile?')) {
    form.delete(route('search-profiles.destroy', props.profile.id));
  }
}

function keywordList(field: 'role_keywords' | 'locations' | 'industries' | 'seniority_levels' | 'excluded_companies') {
  return {
    get: () => (form[field] as string[]).join(', '),
    set: (value: string) => {
      form[field] = value.split(',').map((s) => s.trim()).filter(Boolean);
    },
  };
}

const roleKeywordsInput = keywordList('role_keywords');
const locationsInput = keywordList('locations');
const industriesInput = keywordList('industries');
const seniorityInput = keywordList('seniority_levels');
const excludedInput = keywordList('excluded_companies');
</script>

<template>
  <form class="space-y-5" @submit.prevent="submit">
    <div>
      <label class="block text-sm text-ink-2">Name</label>
      <input v-model="form.name" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" />
      <p v-if="form.errors.name" class="mt-1 text-sm text-oxblood">{{ form.errors.name }}</p>
    </div>

    <div>
      <label class="block text-sm text-ink-2">Linked resume</label>
      <select v-model="form.resume_id" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2">
        <option :value="null">— none —</option>
        <option v-for="r in resumes" :key="r.id" :value="r.id">{{ r.name }}</option>
      </select>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
      <div>
        <label class="block text-sm text-ink-2">Role keywords (comma-separated)</label>
        <input :value="roleKeywordsInput.get()" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" @input="(e) => roleKeywordsInput.set((e.target as HTMLInputElement).value)" />
      </div>
      <div>
        <label class="block text-sm text-ink-2">Locations</label>
        <input :value="locationsInput.get()" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" @input="(e) => locationsInput.set((e.target as HTMLInputElement).value)" />
      </div>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
      <div>
        <label class="block text-sm text-ink-2">Remote mode</label>
        <select v-model="form.remote_mode" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2">
          <option value="any">Any</option>
          <option value="remote_only">Remote only</option>
          <option value="hybrid_ok">Hybrid OK</option>
          <option value="onsite">On-site</option>
        </select>
      </div>
      <div>
        <label class="block text-sm text-ink-2">Min comp</label>
        <input v-model.number="form.comp_min" type="number" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" />
      </div>
      <div>
        <label class="block text-sm text-ink-2">Currency</label>
        <input v-model="form.comp_currency" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" maxlength="3" />
      </div>
    </div>

    <div>
      <label class="block text-sm text-ink-2">Industries</label>
      <input :value="industriesInput.get()" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" @input="(e) => industriesInput.set((e.target as HTMLInputElement).value)" />
    </div>

    <div>
      <label class="block text-sm text-ink-2">Seniority levels</label>
      <input :value="seniorityInput.get()" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" @input="(e) => seniorityInput.set((e.target as HTMLInputElement).value)" />
    </div>

    <div>
      <label class="block text-sm text-ink-2">Excluded companies</label>
      <input :value="excludedInput.get()" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" @input="(e) => excludedInput.set((e.target as HTMLInputElement).value)" />
    </div>

    <label class="inline-flex items-center gap-2">
      <input v-model="form.is_active" type="checkbox" />
      <span class="text-sm text-ink-2">Active (generate matches for this profile)</span>
    </label>

    <div class="flex justify-between">
      <button v-if="isEditing" type="button" class="text-sm text-ink-3 hover:text-oxblood" @click="destroyProfile">Delete profile</button>
      <button type="submit" class="rounded-full bg-oxblood px-5 py-2 text-sm font-medium text-bone disabled:opacity-50" :disabled="form.processing">
        {{ isEditing ? 'Save changes' : 'Create profile' }}
      </button>
    </div>
  </form>
</template>
```

- [ ] **Step 10: Write `Index.vue` and `Edit.vue`**

`resources/js/pages/search-profiles/Index.vue`:

```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
  profiles: Array<{ id: number; name: string; is_active: boolean; resume?: { id: number; name: string } | null }>;
}>();
</script>

<template>
  <Head title="Search profiles" />
  <AppLayout>
    <div class="mx-auto max-w-3xl px-6 py-10">
      <header class="mb-8 flex items-end justify-between">
        <div>
          <h1 class="font-display text-4xl text-ink">Search profiles</h1>
          <p class="mt-1 text-ink-3">Each profile is a separate ranked feed.</p>
        </div>
        <Link :href="route('search-profiles.create')" class="rounded-full bg-oxblood px-5 py-2 text-sm font-medium text-bone">
          New profile
        </Link>
      </header>

      <ul v-if="profiles.length" class="divide-y divide-hairline">
        <li v-for="p in profiles" :key="p.id" class="flex items-center justify-between py-4">
          <div>
            <Link :href="route('search-profiles.edit', p.id)" class="font-display text-lg text-ink hover:text-oxblood">{{ p.name }}</Link>
            <p class="text-xs text-ink-3">
              <span v-if="p.resume">Linked to {{ p.resume.name }}</span>
              <span v-else>No linked resume</span>
              · {{ p.is_active ? 'Active' : 'Paused' }}
            </p>
          </div>
          <Link :href="route('feed.show', p.id)" class="text-sm text-oxblood hover:underline">View feed &rarr;</Link>
        </li>
      </ul>

      <div v-else class="rounded-lg border border-hairline bg-bone-2 px-6 py-12 text-center">
        <p class="text-ink-2">No profiles yet. Create one to start seeing ranked jobs.</p>
      </div>
    </div>
  </AppLayout>
</template>
```

`resources/js/pages/search-profiles/Edit.vue`:

```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import ProfileForm from '@/components/search-profiles/ProfileForm.vue';

defineProps<{
  profile: Record<string, any>;
  resumes: Array<{ id: number; name: string }>;
}>();
</script>

<template>
  <Head :title="profile.id ? 'Edit search profile' : 'New search profile'" />
  <AppLayout>
    <div class="mx-auto max-w-3xl px-6 py-10">
      <Link :href="route('search-profiles.index')" class="text-sm text-ink-3 hover:text-ink">&larr; All profiles</Link>
      <h1 class="mt-4 font-display text-4xl text-ink">
        {{ profile.id ? profile.name : 'New search profile' }}
      </h1>
      <div class="mt-8">
        <ProfileForm :profile="profile" :resumes="resumes" />
      </div>
    </div>
  </AppLayout>
</template>
```

- [ ] **Step 11: Run migrations + tests**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan migrate --no-interaction
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=SearchProfileControllerTest
```

Expected: all tests pass.

- [ ] **Step 12: Format + commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_04_21_100300_create_search_profiles_table.php app/Models/SearchProfile.php app/Models/User.php database/factories/SearchProfileFactory.php app/Http/Controllers/SearchProfiles app/Http/Requests/SearchProfiles routes/web.php resources/js/pages/search-profiles resources/js/components/search-profiles tests/Feature/SearchProfiles
git commit -m "$(cat <<'EOF'
feat: search profiles — schema, CRUD, and editor UI

search_profiles rows carry all seven hard-filter fields the product
spec calls out (role keywords, locations, remote mode, comp floor,
industries, seniority levels, excluded companies) plus an is_active
flag. resume_id is a nullable FK with ON DELETE SET NULL so deleting
a resume doesn't cascade-nuke the profiles that referenced it.

Inertia pages expose a list and an editor whose form takes
comma-separated keyword inputs and a resume selector. The "View feed"
link is scaffolded; the feed endpoint is wired in Plan 2 Task 13.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: `job_sources` + `jobs` schema, models, seeder

**Files:**
- Create: `database/migrations/2026_04_21_100400_create_job_sources_table.php`
- Create: `database/migrations/2026_04_21_100500_create_jobs_canonical_table.php`
- Create: `app/Models/JobSource.php`
- Create: `app/Models/CanonicalJob.php` (Eloquent model — DTO lives in a separate class, see Task 8)
- Create: `database/seeders/JobSourcesSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`

- [ ] **Step 1: Write the `job_sources` migration**

`database/migrations/2026_04_21_100400_create_job_sources_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('job_sources', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 32);
            $table->string('identifier', 120)->nullable();
            $table->string('display_name', 160);
            $table->boolean('is_enabled')->default(true);
            $table->integer('fetch_cadence_minutes')->default(60);
            $table->timestamp('last_fetched_at')->nullable();
            $table->text('last_error')->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->timestamps();

            $table->unique(['source_type', 'identifier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_sources');
    }
};
```

- [ ] **Step 2: Write the `jobs` migration**

`database/migrations/2026_04_21_100500_create_jobs_canonical_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Table name is 'canonical_jobs' to avoid colliding with Laravel's built-in 'jobs' queue table.
        Schema::create('canonical_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('source_id', 120);
            $table->string('source_board', 120)->nullable();
            $table->string('company_name', 160);
            $table->string('title', 240);
            $table->text('description');
            $table->string('location_raw', 240)->nullable();
            $table->jsonb('locations')->default('[]');
            $table->string('remote_mode', 16)->default('any');
            $table->integer('comp_min')->nullable();
            $table->integer('comp_max')->nullable();
            $table->string('comp_currency', 8)->nullable();
            $table->string('seniority', 40)->nullable();
            $table->string('employment_type', 40)->nullable();
            $table->string('apply_url', 500);
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->jsonb('raw_payload')->default('{}');
            $table->timestamps();

            $table->unique(['source', 'source_id']);
            $table->index('posted_at');
            $table->index(['source', 'closed_at']);
        });

        DB::statement('ALTER TABLE canonical_jobs ADD COLUMN embedding vector(1536)');
        DB::statement('CREATE INDEX canonical_jobs_embedding_ivfflat ON canonical_jobs USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('canonical_jobs');
    }
};
```

- [ ] **Step 3: Write the `JobSource` model**

`app/Models/JobSource.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_type',
        'identifier',
        'display_name',
        'is_enabled',
        'fetch_cadence_minutes',
        'last_fetched_at',
        'last_error',
        'consecutive_failures',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'last_fetched_at' => 'datetime',
        'fetch_cadence_minutes' => 'integer',
        'consecutive_failures' => 'integer',
    ];
}
```

- [ ] **Step 4: Write the `CanonicalJob` Eloquent model**

`app/Models/CanonicalJob.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CanonicalJob extends Model
{
    use HasFactory;

    protected $table = 'canonical_jobs';

    protected $fillable = [
        'source', 'source_id', 'source_board', 'company_name', 'title', 'description',
        'location_raw', 'locations', 'remote_mode', 'comp_min', 'comp_max', 'comp_currency',
        'seniority', 'employment_type', 'apply_url', 'posted_at', 'last_seen_at', 'closed_at',
        'raw_payload',
    ];

    protected $casts = [
        'locations' => 'array',
        'raw_payload' => 'array',
        'posted_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'closed_at' => 'datetime',
    ];
}
```

- [ ] **Step 5: Write the seeder**

`database/seeders/JobSourcesSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\JobSource;
use Illuminate\Database\Seeder;

class JobSourcesSeeder extends Seeder
{
    public function run(): void
    {
        $seeds = [
            // Greenhouse boards (public, well-known engineering companies with open job feeds).
            ['source_type' => 'greenhouse', 'identifier' => 'stripe',      'display_name' => 'Stripe (Greenhouse)'],
            ['source_type' => 'greenhouse', 'identifier' => 'airbnb',      'display_name' => 'Airbnb (Greenhouse)'],
            ['source_type' => 'greenhouse', 'identifier' => 'notion',      'display_name' => 'Notion (Greenhouse)'],
            ['source_type' => 'greenhouse', 'identifier' => 'figma',       'display_name' => 'Figma (Greenhouse)'],
            ['source_type' => 'greenhouse', 'identifier' => 'linear',      'display_name' => 'Linear (Greenhouse)'],

            // Lever companies.
            ['source_type' => 'lever',      'identifier' => 'netflix',     'display_name' => 'Netflix (Lever)'],
            ['source_type' => 'lever',      'identifier' => 'shopify',     'display_name' => 'Shopify (Lever)'],
            ['source_type' => 'lever',      'identifier' => 'palantir',    'display_name' => 'Palantir (Lever)'],

            // RemoteOK is a single aggregator.
            ['source_type' => 'remoteok',   'identifier' => null,          'display_name' => 'RemoteOK (all tags)'],
        ];

        foreach ($seeds as $seed) {
            JobSource::updateOrCreate(
                ['source_type' => $seed['source_type'], 'identifier' => $seed['identifier']],
                $seed + ['is_enabled' => true, 'fetch_cadence_minutes' => 60],
            );
        }
    }
}
```

- [ ] **Step 6: Hook the seeder into `DatabaseSeeder`**

Edit `database/seeders/DatabaseSeeder.php` — add `$this->call(JobSourcesSeeder::class);` after the existing `SiteSettingsSeeder` call.

- [ ] **Step 7: Run migrations + seed**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan migrate --no-interaction
/c/Users/david/.config/herd/bin/php84/php.exe artisan db:seed --class=JobSourcesSeeder --no-interaction
```

Expected: both migrations run; 9 rows in `job_sources`. Verify:

```bash
docker exec throughline-postgres psql -U throughline -d throughline -c "SELECT source_type, identifier, display_name FROM job_sources ORDER BY source_type, identifier;"
```

- [ ] **Step 8: Format + commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_04_21_100400_create_job_sources_table.php database/migrations/2026_04_21_100500_create_jobs_canonical_table.php app/Models/JobSource.php app/Models/CanonicalJob.php database/seeders/JobSourcesSeeder.php database/seeders/DatabaseSeeder.php
git commit -m "$(cat <<'EOF'
feat: job_sources + canonical_jobs schema and seed list

canonical_jobs takes the table name to avoid colliding with Laravel's
built-in queue 'jobs' table. Unique on (source, source_id) keeps
upserts idempotent; ivfflat vector index on embedding prepares the
matcher for scale without requiring a follow-up migration once data
lands. job_sources is unique on (source_type, identifier) — one
Greenhouse board per row, one Lever company per row, one RemoteOK
aggregator row with null identifier.

JobSourcesSeeder ships a curated starter list (5 Greenhouse, 3 Lever,
1 RemoteOK) suitable for dogfooding.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Driver contract + registry + `GreenhouseDriver`

**Files:**
- Create: `app/Contracts/Jobs/JobSourceDriver.php`
- Create: `app/DTO/CanonicalJobData.php`
- Create: `app/Services/Jobs/DriverRegistry.php`
- Create: `app/Services/Jobs/Exceptions/DriverFetchFailed.php`
- Create: `app/Services/Jobs/Drivers/GreenhouseDriver.php`
- Create: `config/throughline.php`
- Create: `tests/Fixtures/drivers/greenhouse/stripe-happy.json`
- Create: `tests/Fixtures/drivers/greenhouse/stripe-missing-comp.json`
- Create: `tests/Unit/Jobs/Drivers/GreenhouseDriverTest.php`

- [ ] **Step 1: Write the contract**

`app/Contracts/Jobs/JobSourceDriver.php`:

```php
<?php

namespace App\Contracts\Jobs;

use App\Models\JobSource;

interface JobSourceDriver
{
    public function sourceType(): string;

    /**
     * @return iterable<\App\DTO\CanonicalJobData>
     */
    public function fetch(JobSource $source): iterable;

    public function validateIdentifier(?string $identifier): bool;
}
```

- [ ] **Step 2: Write the DTO**

`app/DTO/CanonicalJobData.php`:

```php
<?php

namespace App\DTO;

use DateTimeImmutable;

final readonly class CanonicalJobData
{
    public function __construct(
        public string $sourceId,
        public ?string $sourceBoard,
        public string $companyName,
        public string $title,
        public string $description,
        public ?string $locationRaw,
        public array $locations,
        public string $remoteMode,
        public ?int $compMin,
        public ?int $compMax,
        public ?string $compCurrency,
        public ?string $seniority,
        public ?string $employmentType,
        public string $applyUrl,
        public DateTimeImmutable $postedAt,
        public array $rawPayload,
    ) {
    }

    public function toEloquentAttributes(string $source): array
    {
        return [
            'source' => $source,
            'source_id' => $this->sourceId,
            'source_board' => $this->sourceBoard,
            'company_name' => $this->companyName,
            'title' => $this->title,
            'description' => $this->description,
            'location_raw' => $this->locationRaw,
            'locations' => $this->locations,
            'remote_mode' => $this->remoteMode,
            'comp_min' => $this->compMin,
            'comp_max' => $this->compMax,
            'comp_currency' => $this->compCurrency,
            'seniority' => $this->seniority,
            'employment_type' => $this->employmentType,
            'apply_url' => $this->applyUrl,
            'posted_at' => $this->postedAt,
            'last_seen_at' => now(),
            'raw_payload' => $this->rawPayload,
        ];
    }
}
```

- [ ] **Step 3: Write the driver registry and exception**

`app/Services/Jobs/Exceptions/DriverFetchFailed.php`:

```php
<?php

namespace App\Services\Jobs\Exceptions;

use RuntimeException;
use Throwable;

class DriverFetchFailed extends RuntimeException
{
    public function __construct(
        public readonly string $sourceType,
        public readonly ?string $identifier,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('[%s:%s] %s', $sourceType, $identifier ?? '-', $message),
            0,
            $previous,
        );
    }
}
```

`app/Services/Jobs/DriverRegistry.php`:

```php
<?php

namespace App\Services\Jobs;

use App\Contracts\Jobs\JobSourceDriver;

class DriverRegistry
{
    /** @var array<string, class-string<JobSourceDriver>> */
    protected array $map;

    public function __construct()
    {
        $this->map = config('throughline.drivers', []);
    }

    public function driverFor(string $sourceType): JobSourceDriver
    {
        $class = $this->map[$sourceType] ?? null;
        if (!$class || !class_exists($class)) {
            throw new \InvalidArgumentException("No driver registered for source_type '{$sourceType}'.");
        }

        return app($class);
    }

    public function supportedTypes(): array
    {
        return array_keys($this->map);
    }
}
```

- [ ] **Step 4: Write the throughline config**

`config/throughline.php`:

```php
<?php

return [
    'drivers' => [
        'greenhouse' => \App\Services\Jobs\Drivers\GreenhouseDriver::class,
        'lever'      => \App\Services\Jobs\Drivers\LeverDriver::class,
        'remoteok'   => \App\Services\Jobs\Drivers\RemoteOkDriver::class,
    ],
];
```

(LeverDriver and RemoteOkDriver don't exist yet — Tasks 9 and 10. The registry tolerates missing classes only at resolution time, so config wiring ahead of implementation is safe as long as no code resolves those types yet.)

- [ ] **Step 5: Capture a real Greenhouse API response as fixture**

```bash
curl -s 'https://boards-api.greenhouse.io/v1/boards/stripe/jobs?content=true' | head -c 200000 > tests/Fixtures/drivers/greenhouse/stripe-happy.json
```

Verify the file exists and contains a `jobs` array:

```bash
/c/Users/david/.config/herd/bin/php84/php.exe -r "echo count(json_decode(file_get_contents('tests/Fixtures/drivers/greenhouse/stripe-happy.json'), true)['jobs']);"
```

Expected: a positive integer. If curl fails (network restricted in CI), hand-write a minimal fixture with the shape below:

```json
{
  "jobs": [
    {
      "id": 4288123,
      "title": "Software Engineer, Platform",
      "updated_at": "2026-04-15T19:20:00Z",
      "location": {"name": "San Francisco, CA"},
      "content": "<p>Work on core platform...</p>",
      "absolute_url": "https://boards.greenhouse.io/stripe/jobs/4288123",
      "departments": [{"name": "Engineering"}],
      "offices": [{"name": "San Francisco"}, {"name": "Remote"}],
      "metadata": [
        {"name": "compensation", "value": "$180,000 - $240,000 USD"}
      ]
    }
  ]
}
```

Also write a second fixture `tests/Fixtures/drivers/greenhouse/stripe-missing-comp.json`:

```json
{
  "jobs": [
    {
      "id": 4288124,
      "title": "Lead Infra Engineer",
      "updated_at": "2026-04-16T15:00:00Z",
      "location": {"name": "Remote — North America"},
      "content": "Own SRE practice...",
      "absolute_url": "https://boards.greenhouse.io/stripe/jobs/4288124",
      "departments": [],
      "offices": [{"name": "Remote"}],
      "metadata": []
    }
  ]
}
```

- [ ] **Step 6: Write the driver test first (TDD)**

`tests/Unit/Jobs/Drivers/GreenhouseDriverTest.php`:

```php
<?php

namespace Tests\Unit\Jobs\Drivers;

use App\Models\JobSource;
use App\Services\Jobs\Drivers\GreenhouseDriver;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GreenhouseDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_yields_canonical_jobs_from_happy_fixture(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/greenhouse/stripe-happy.json'));
        Http::fake(['boards-api.greenhouse.io/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'greenhouse', 'identifier' => 'stripe']);
        $jobs = iterator_to_array(app(GreenhouseDriver::class)->fetch($source));

        $this->assertGreaterThan(0, count($jobs));
        $this->assertSame('greenhouse', $jobs[0]->sourceBoard === 'stripe' ? 'greenhouse' : 'greenhouse');
        $this->assertNotEmpty($jobs[0]->sourceId);
        $this->assertNotEmpty($jobs[0]->title);
        $this->assertNotEmpty($jobs[0]->applyUrl);
    }

    public function test_handles_missing_comp_metadata(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/greenhouse/stripe-missing-comp.json'));
        Http::fake(['boards-api.greenhouse.io/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'greenhouse', 'identifier' => 'stripe']);
        $jobs = iterator_to_array(app(GreenhouseDriver::class)->fetch($source));

        $this->assertNull($jobs[0]->compMin);
        $this->assertNull($jobs[0]->compMax);
    }

    public function test_failed_http_throws_driver_fetch_failed(): void
    {
        Http::fake(['boards-api.greenhouse.io/*' => Http::response('Not found', 404)]);

        $source = JobSource::factory()->create(['source_type' => 'greenhouse', 'identifier' => 'missing']);

        $this->expectException(DriverFetchFailed::class);
        iterator_to_array(app(GreenhouseDriver::class)->fetch($source));
    }

    public function test_validateIdentifier_requires_nonempty_slug(): void
    {
        $driver = app(GreenhouseDriver::class);
        $this->assertFalse($driver->validateIdentifier(null));
        $this->assertFalse($driver->validateIdentifier(''));
        $this->assertTrue($driver->validateIdentifier('stripe'));
    }
}
```

Add a `JobSourceFactory` so the factory call above resolves:

`database/factories/JobSourceFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\JobSource;
use Illuminate\Database\Eloquent\Factories\Factory;

class JobSourceFactory extends Factory
{
    protected $model = JobSource::class;

    public function definition(): array
    {
        return [
            'source_type' => 'greenhouse',
            'identifier' => fake()->slug(1),
            'display_name' => fake()->company(),
            'is_enabled' => true,
            'fetch_cadence_minutes' => 60,
        ];
    }
}
```

- [ ] **Step 7: Run the test — expect fail**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=GreenhouseDriverTest
```

Expected: FAIL — `GreenhouseDriver` class missing.

- [ ] **Step 8: Implement `GreenhouseDriver`**

`app/Services/Jobs/Drivers/GreenhouseDriver.php`:

```php
<?php

namespace App\Services\Jobs\Drivers;

use App\Contracts\Jobs\JobSourceDriver;
use App\DTO\CanonicalJobData;
use App\Models\JobSource;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use DateTimeImmutable;
use Generator;
use Illuminate\Support\Facades\Http;

class GreenhouseDriver implements JobSourceDriver
{
    public function sourceType(): string
    {
        return 'greenhouse';
    }

    public function validateIdentifier(?string $identifier): bool
    {
        return is_string($identifier) && strlen(trim($identifier)) > 0;
    }

    public function fetch(JobSource $source): Generator
    {
        if (!$this->validateIdentifier($source->identifier)) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, 'identifier required');
        }

        $url = "https://boards-api.greenhouse.io/v1/boards/{$source->identifier}/jobs?content=true";

        try {
            $response = Http::acceptJson()->timeout(30)->get($url);
        } catch (\Throwable $e) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, 'HTTP error: ' . $e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, "Upstream returned {$response->status()}");
        }

        $body = $response->json();
        $jobs = $body['jobs'] ?? [];

        foreach ($jobs as $raw) {
            yield $this->normalize($raw, $source);
        }
    }

    protected function normalize(array $raw, JobSource $source): CanonicalJobData
    {
        [$compMin, $compMax, $compCurrency] = $this->extractComp($raw['metadata'] ?? []);
        $locations = $this->extractLocations($raw['offices'] ?? [], $raw['location']['name'] ?? null);
        $remote = $this->inferRemote($locations, $raw['location']['name'] ?? '');

        return new CanonicalJobData(
            sourceId: (string) $raw['id'],
            sourceBoard: $source->identifier,
            companyName: ucfirst($source->identifier ?? 'Unknown'),
            title: $raw['title'] ?? 'Untitled',
            description: strip_tags($raw['content'] ?? ''),
            locationRaw: $raw['location']['name'] ?? null,
            locations: $locations,
            remoteMode: $remote,
            compMin: $compMin,
            compMax: $compMax,
            compCurrency: $compCurrency,
            seniority: null,
            employmentType: null,
            applyUrl: $raw['absolute_url'] ?? '',
            postedAt: new DateTimeImmutable($raw['updated_at'] ?? 'now'),
            rawPayload: $raw,
        );
    }

    protected function extractComp(array $metadata): array
    {
        foreach ($metadata as $entry) {
            $name = strtolower($entry['name'] ?? '');
            if (!str_contains($name, 'compensation') && !str_contains($name, 'salary')) {
                continue;
            }
            $value = (string) ($entry['value'] ?? '');
            if (preg_match('/\$?([\d,]+)(?:\s*-\s*\$?([\d,]+))?\s*([A-Z]{3})?/', $value, $m)) {
                return [
                    (int) str_replace(',', '', $m[1]),
                    isset($m[2]) && $m[2] !== '' ? (int) str_replace(',', '', $m[2]) : null,
                    $m[3] ?? 'USD',
                ];
            }
        }
        return [null, null, null];
    }

    protected function extractLocations(array $offices, ?string $fallback): array
    {
        $names = array_filter(array_map(fn ($o) => $o['name'] ?? null, $offices));
        return $names ?: array_filter([$fallback]);
    }

    protected function inferRemote(array $locations, string $raw): string
    {
        $haystack = strtolower(implode(' ', $locations) . ' ' . $raw);
        if (str_contains($haystack, 'remote')) {
            return count($locations) > 1 ? 'hybrid_ok' : 'remote_only';
        }
        return 'onsite';
    }
}
```

- [ ] **Step 9: Run the test to confirm pass**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=GreenhouseDriverTest
```

Expected: all four tests pass.

- [ ] **Step 10: Format + commit**

```bash
vendor/bin/pint --dirty
git add app/Contracts/Jobs app/DTO app/Services/Jobs config/throughline.php database/factories/JobSourceFactory.php tests/Fixtures/drivers/greenhouse tests/Unit/Jobs/Drivers/GreenhouseDriverTest.php
git commit -m "$(cat <<'EOF'
feat: JobSourceDriver contract + DriverRegistry + GreenhouseDriver

Contract defines sourceType/fetch/validateIdentifier; fetch is a
generator so paginated boards never materialize in memory. Registry
reads config/throughline.php so adding a driver in Plan 3+ is a
one-line change. CanonicalJobData DTO carries the shape every driver
normalizes to; toEloquentAttributes() is what FetchJobSource uses to
upsert canonical_jobs rows.

GreenhouseDriver reads the public boards-api endpoint, normalizes
comp from metadata (regex over the string format Greenhouse boards
use), and infers remote mode by scanning offices + location strings.
Two fixtures cover happy path and the comp-absent edge case; a third
test verifies 404 → DriverFetchFailed translation.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: `LeverDriver`

**Files:**
- Create: `app/Services/Jobs/Drivers/LeverDriver.php`
- Create: `tests/Fixtures/drivers/lever/netflix-happy.json`
- Create: `tests/Fixtures/drivers/lever/netflix-multilocation.json`
- Create: `tests/Unit/Jobs/Drivers/LeverDriverTest.php`

- [ ] **Step 1: Capture the fixture**

```bash
curl -s 'https://api.lever.co/v0/postings/netflix?mode=json' | head -c 200000 > tests/Fixtures/drivers/netflix-happy.json
```

If capturing from the live endpoint isn't possible, hand-write with this shape:

```json
[
  {
    "id": "2f1a8d5e-0000-0000-0000-abcdef123456",
    "text": "Senior Backend Engineer",
    "createdAt": 1744740000000,
    "categories": {
      "commitment": "Full-time",
      "location": "Los Angeles, CA",
      "team": "Streaming Services",
      "department": "Engineering",
      "level": "Senior"
    },
    "hostedUrl": "https://jobs.lever.co/netflix/2f1a8d5e",
    "applyUrl": "https://jobs.lever.co/netflix/2f1a8d5e/apply",
    "descriptionPlain": "We are looking for...",
    "lists": [{"text": "Requirements", "content": "<p>5+ years...</p>"}],
    "salaryRange": {"min": 220000, "max": 380000, "currency": "USD", "interval": "per-year-salary"},
    "workplaceType": "on-site",
    "additional": "",
    "additionalPlain": ""
  }
]
```

The multilocation fixture has the same root shape but with `"location": "Los Angeles, CA / Remote - United States"`, `"workplaceType": "hybrid"`.

- [ ] **Step 2: Write the test**

`tests/Unit/Jobs/Drivers/LeverDriverTest.php`:

```php
<?php

namespace Tests\Unit\Jobs\Drivers;

use App\Models\JobSource;
use App\Services\Jobs\Drivers\LeverDriver;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LeverDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_yields_canonical_jobs(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/lever/netflix-happy.json'));
        Http::fake(['api.lever.co/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'lever', 'identifier' => 'netflix']);
        $jobs = iterator_to_array(app(LeverDriver::class)->fetch($source));

        $this->assertGreaterThan(0, count($jobs));
        $this->assertSame(220000, $jobs[0]->compMin);
        $this->assertSame(380000, $jobs[0]->compMax);
        $this->assertSame('USD', $jobs[0]->compCurrency);
        $this->assertSame('onsite', $jobs[0]->remoteMode);
    }

    public function test_multilocation_parses_as_hybrid(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/lever/netflix-multilocation.json'));
        Http::fake(['api.lever.co/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'lever', 'identifier' => 'netflix']);
        $jobs = iterator_to_array(app(LeverDriver::class)->fetch($source));

        $this->assertSame('hybrid_ok', $jobs[0]->remoteMode);
        $this->assertCount(2, $jobs[0]->locations);
    }

    public function test_validateIdentifier_requires_nonempty(): void
    {
        $driver = app(LeverDriver::class);
        $this->assertFalse($driver->validateIdentifier(null));
        $this->assertTrue($driver->validateIdentifier('netflix'));
    }

    public function test_http_failure_translates(): void
    {
        Http::fake(['api.lever.co/*' => Http::response('Not found', 404)]);

        $source = JobSource::factory()->create(['source_type' => 'lever', 'identifier' => 'missing']);
        $this->expectException(DriverFetchFailed::class);
        iterator_to_array(app(LeverDriver::class)->fetch($source));
    }
}
```

- [ ] **Step 3: Run the test — expect fail**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=LeverDriverTest
```

Expected: FAIL.

- [ ] **Step 4: Implement `LeverDriver`**

`app/Services/Jobs/Drivers/LeverDriver.php`:

```php
<?php

namespace App\Services\Jobs\Drivers;

use App\Contracts\Jobs\JobSourceDriver;
use App\DTO\CanonicalJobData;
use App\Models\JobSource;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use DateTimeImmutable;
use Generator;
use Illuminate\Support\Facades\Http;

class LeverDriver implements JobSourceDriver
{
    public function sourceType(): string
    {
        return 'lever';
    }

    public function validateIdentifier(?string $identifier): bool
    {
        return is_string($identifier) && strlen(trim($identifier)) > 0;
    }

    public function fetch(JobSource $source): Generator
    {
        if (!$this->validateIdentifier($source->identifier)) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, 'identifier required');
        }

        $url = "https://api.lever.co/v0/postings/{$source->identifier}?mode=json";

        try {
            $response = Http::acceptJson()->timeout(30)->get($url);
        } catch (\Throwable $e) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, 'HTTP error: ' . $e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, "Upstream returned {$response->status()}");
        }

        foreach ($response->json() ?? [] as $raw) {
            yield $this->normalize($raw, $source);
        }
    }

    protected function normalize(array $raw, JobSource $source): CanonicalJobData
    {
        $categories = $raw['categories'] ?? [];
        $locationRaw = $categories['location'] ?? null;
        $locations = $locationRaw ? array_map('trim', preg_split('~/|,~', $locationRaw)) : [];
        $locations = array_values(array_filter($locations));

        $workplace = strtolower($raw['workplaceType'] ?? 'on-site');
        $remote = match ($workplace) {
            'remote' => 'remote_only',
            'hybrid' => 'hybrid_ok',
            default => 'onsite',
        };

        $salary = $raw['salaryRange'] ?? [];
        $description = trim(($raw['descriptionPlain'] ?? '') . "\n\n" . ($raw['additionalPlain'] ?? ''));

        return new CanonicalJobData(
            sourceId: (string) ($raw['id'] ?? ''),
            sourceBoard: $source->identifier,
            companyName: ucfirst($source->identifier ?? 'Unknown'),
            title: $raw['text'] ?? 'Untitled',
            description: $description !== '' ? $description : strip_tags($raw['description'] ?? ''),
            locationRaw: $locationRaw,
            locations: $locations,
            remoteMode: $remote,
            compMin: isset($salary['min']) ? (int) $salary['min'] : null,
            compMax: isset($salary['max']) ? (int) $salary['max'] : null,
            compCurrency: $salary['currency'] ?? null,
            seniority: $categories['level'] ?? null,
            employmentType: $categories['commitment'] ?? null,
            applyUrl: $raw['hostedUrl'] ?? ($raw['applyUrl'] ?? ''),
            postedAt: new DateTimeImmutable('@' . (int) (($raw['createdAt'] ?? time() * 1000) / 1000)),
            rawPayload: $raw,
        );
    }
}
```

- [ ] **Step 5: Run the test to confirm pass**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=LeverDriverTest
```

Expected: 4 tests pass.

- [ ] **Step 6: Format + commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Jobs/Drivers/LeverDriver.php tests/Fixtures/drivers/lever tests/Unit/Jobs/Drivers/LeverDriverTest.php
git commit -m "feat: LeverDriver with fixture-driven happy + multilocation tests"
```

---

## Task 10: `RemoteOkDriver`

**Files:**
- Create: `app/Services/Jobs/Drivers/RemoteOkDriver.php`
- Create: `tests/Fixtures/drivers/remoteok/feed-happy.json`
- Create: `tests/Unit/Jobs/Drivers/RemoteOkDriverTest.php`

- [ ] **Step 1: Capture the fixture**

```bash
curl -s 'https://remoteok.com/api' | head -c 200000 > tests/Fixtures/drivers/remoteok/feed-happy.json
```

Fallback hand-written shape (RemoteOK's real feed is an array whose first element is a metadata/legal object; drivers must skip it):

```json
[
  {"legal": "See remoteok.com/terms for usage rights."},
  {
    "id": "987654",
    "slug": "acme-senior-backend-engineer-987654",
    "company": "Acme",
    "position": "Senior Backend Engineer",
    "tags": ["backend", "python", "aws"],
    "location": "Worldwide",
    "salary_min": 120000,
    "salary_max": 180000,
    "apply_url": "https://remoteok.com/remote-jobs/987654-senior-backend-engineer-acme",
    "url": "https://remoteok.com/remote-jobs/987654",
    "description": "Build and maintain our core services...",
    "date": "2026-04-15T10:00:00+00:00",
    "company_logo": null
  },
  {
    "id": "987655",
    "slug": "beta-engineer-987655",
    "company": "Beta",
    "position": "Platform Engineer",
    "tags": ["devops"],
    "location": "Europe only",
    "salary_min": null,
    "salary_max": null,
    "apply_url": "https://remoteok.com/remote-jobs/987655",
    "url": "https://remoteok.com/remote-jobs/987655",
    "description": "Help us scale...",
    "date": "2026-04-16T08:00:00+00:00",
    "company_logo": null
  }
]
```

- [ ] **Step 2: Write the test**

`tests/Unit/Jobs/Drivers/RemoteOkDriverTest.php`:

```php
<?php

namespace Tests\Unit\Jobs\Drivers;

use App\Models\JobSource;
use App\Services\Jobs\Drivers\RemoteOkDriver;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemoteOkDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_skips_metadata_entry_and_yields_jobs(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/remoteok/feed-happy.json'));
        Http::fake(['remoteok.com/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'remoteok', 'identifier' => null]);
        $jobs = iterator_to_array(app(RemoteOkDriver::class)->fetch($source));

        $this->assertCount(2, $jobs);
        $this->assertSame('remote_only', $jobs[0]->remoteMode);
        $this->assertSame('Acme', $jobs[0]->companyName);
    }

    public function test_null_comp_is_preserved(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/remoteok/feed-happy.json'));
        Http::fake(['remoteok.com/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'remoteok', 'identifier' => null]);
        $jobs = iterator_to_array(app(RemoteOkDriver::class)->fetch($source));

        $this->assertNull($jobs[1]->compMin);
        $this->assertNull($jobs[1]->compMax);
    }

    public function test_validateIdentifier_accepts_null(): void
    {
        $driver = app(RemoteOkDriver::class);
        $this->assertTrue($driver->validateIdentifier(null));
        $this->assertTrue($driver->validateIdentifier(''));
    }

    public function test_http_failure_translates(): void
    {
        Http::fake(['remoteok.com/*' => Http::response('', 500)]);

        $source = JobSource::factory()->create(['source_type' => 'remoteok', 'identifier' => null]);
        $this->expectException(DriverFetchFailed::class);
        iterator_to_array(app(RemoteOkDriver::class)->fetch($source));
    }
}
```

- [ ] **Step 3: Run the test — expect fail**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=RemoteOkDriverTest
```

Expected: FAIL.

- [ ] **Step 4: Implement `RemoteOkDriver`**

`app/Services/Jobs/Drivers/RemoteOkDriver.php`:

```php
<?php

namespace App\Services\Jobs\Drivers;

use App\Contracts\Jobs\JobSourceDriver;
use App\DTO\CanonicalJobData;
use App\Models\JobSource;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use DateTimeImmutable;
use Generator;
use Illuminate\Support\Facades\Http;

class RemoteOkDriver implements JobSourceDriver
{
    public function sourceType(): string
    {
        return 'remoteok';
    }

    public function validateIdentifier(?string $identifier): bool
    {
        // RemoteOK is a single global feed; identifier is not meaningful.
        return true;
    }

    public function fetch(JobSource $source): Generator
    {
        try {
            $response = Http::acceptJson()->timeout(30)
                ->withHeaders(['User-Agent' => 'ThroughlineJobIngestor/1.0'])
                ->get('https://remoteok.com/api');
        } catch (\Throwable $e) {
            throw new DriverFetchFailed($this->sourceType(), null, 'HTTP error: ' . $e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw new DriverFetchFailed($this->sourceType(), null, "Upstream returned {$response->status()}");
        }

        foreach ($response->json() ?? [] as $entry) {
            if (!isset($entry['id'], $entry['position'])) {
                continue; // skip the legal/metadata element
            }
            yield $this->normalize($entry, $source);
        }
    }

    protected function normalize(array $raw, JobSource $source): CanonicalJobData
    {
        return new CanonicalJobData(
            sourceId: (string) $raw['id'],
            sourceBoard: 'global',
            companyName: $raw['company'] ?? 'Unknown',
            title: $raw['position'] ?? 'Untitled',
            description: strip_tags($raw['description'] ?? ''),
            locationRaw: $raw['location'] ?? null,
            locations: $raw['location'] ? [$raw['location']] : [],
            remoteMode: 'remote_only',
            compMin: isset($raw['salary_min']) && $raw['salary_min'] ? (int) $raw['salary_min'] : null,
            compMax: isset($raw['salary_max']) && $raw['salary_max'] ? (int) $raw['salary_max'] : null,
            compCurrency: isset($raw['salary_min']) && $raw['salary_min'] ? 'USD' : null,
            seniority: null,
            employmentType: null,
            applyUrl: $raw['apply_url'] ?? ($raw['url'] ?? ''),
            postedAt: new DateTimeImmutable($raw['date'] ?? 'now'),
            rawPayload: $raw,
        );
    }
}
```

- [ ] **Step 5: Run the test to confirm pass**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=RemoteOkDriverTest
```

Expected: 4 tests pass.

- [ ] **Step 6: Run all driver tests together**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=DriverTest
```

Expected: 12 tests (4 per driver) pass.

- [ ] **Step 7: Format + commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Jobs/Drivers/RemoteOkDriver.php tests/Fixtures/drivers/remoteok tests/Unit/Jobs/Drivers/RemoteOkDriverTest.php
git commit -m "feat: RemoteOkDriver aggregator with metadata-entry skip"
```

---

## Task 11: `FetchJobSource` + `EmbedJob` + scheduler

**Files:**
- Create: `app/Jobs/FetchJobSource.php`
- Create: `app/Jobs/EmbedJob.php`
- Create: `app/Jobs/CloseStaleJobs.php`
- Modify: `routes/console.php`
- Create: `tests/Feature/Jobs/FetchJobSourceTest.php`
- Create: `tests/Feature/Jobs/EmbedJobTest.php`

- [ ] **Step 1: Write the `FetchJobSource` test**

`tests/Feature/Jobs/FetchJobSourceTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EmbedJob;
use App\Jobs\FetchJobSource;
use App\Models\CanonicalJob;
use App\Models\JobSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FetchJobSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_upserts_canonical_jobs_and_dispatches_embed(): void
    {
        Queue::fake();
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/greenhouse/stripe-happy.json'));
        Http::fake(['boards-api.greenhouse.io/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'greenhouse', 'identifier' => 'stripe']);

        (new FetchJobSource($source->id))->handle(app(\App\Services\Jobs\DriverRegistry::class));

        $this->assertGreaterThan(0, CanonicalJob::count());
        $source->refresh();
        $this->assertNotNull($source->last_fetched_at);
        $this->assertNull($source->last_error);
        Queue::assertPushed(EmbedJob::class);
    }

    public function test_upstream_failure_updates_last_error_and_tracks_consecutive(): void
    {
        Http::fake(['boards-api.greenhouse.io/*' => Http::response('Gone', 410)]);
        $source = JobSource::factory()->create([
            'source_type' => 'greenhouse',
            'identifier' => 'dead',
            'consecutive_failures' => 2,
        ]);

        (new FetchJobSource($source->id))->handle(app(\App\Services\Jobs\DriverRegistry::class));

        $source->refresh();
        $this->assertNotNull($source->last_error);
        $this->assertSame(3, $source->consecutive_failures);
        $this->assertFalse($source->is_enabled, 'auto-pause after 3 consecutive failures');
    }

    public function test_existing_jobs_get_last_seen_refreshed_not_duplicated(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/greenhouse/stripe-happy.json'));
        Http::fake(['boards-api.greenhouse.io/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'greenhouse', 'identifier' => 'stripe']);

        (new FetchJobSource($source->id))->handle(app(\App\Services\Jobs\DriverRegistry::class));
        $initial = CanonicalJob::count();

        (new FetchJobSource($source->id))->handle(app(\App\Services\Jobs\DriverRegistry::class));

        $this->assertSame($initial, CanonicalJob::count(), 'idempotent upsert on (source, source_id)');
    }
}
```

- [ ] **Step 2: Write the `EmbedJob` test**

`tests/Feature/Jobs/EmbedJobTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EmbedJob;
use App\Jobs\FanOutStage1Matches;
use App\Models\CanonicalJob;
use App\Services\Ai\AiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class EmbedJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_vector_and_dispatches_fanout(): void
    {
        Queue::fake();

        $job = CanonicalJob::factory()->create();
        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->with(null)->andReturnSelf();
        $ai->shouldReceive('embed')->with(Mockery::any(), 'job')->andReturn(array_fill(0, 1536, 0.3));
        $this->app->instance(AiClient::class, $ai);

        (new EmbedJob($job->id))->handle($ai);

        $vector = \DB::table('canonical_jobs')->where('id', $job->id)->value('embedding');
        $this->assertNotNull($vector);
        Queue::assertPushed(FanOutStage1Matches::class, fn ($j) => $j->canonicalJobId === $job->id);
    }
}
```

Add a minimal `CanonicalJobFactory`:

`database/factories/CanonicalJobFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\CanonicalJob;
use Illuminate\Database\Eloquent\Factories\Factory;

class CanonicalJobFactory extends Factory
{
    protected $model = CanonicalJob::class;

    public function definition(): array
    {
        return [
            'source' => 'greenhouse',
            'source_id' => fake()->unique()->numerify('########'),
            'source_board' => 'stripe',
            'company_name' => 'Stripe',
            'title' => fake()->jobTitle(),
            'description' => fake()->paragraph(),
            'location_raw' => 'San Francisco, CA',
            'locations' => ['San Francisco'],
            'remote_mode' => 'onsite',
            'apply_url' => fake()->url(),
            'posted_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
```

- [ ] **Step 3: Run both tests — expect fail**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=FetchJobSourceTest
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=EmbedJobTest
```

Expected: FAIL.

- [ ] **Step 4: Implement `FetchJobSource`**

`app/Jobs/FetchJobSource.php`:

```php
<?php

namespace App\Jobs;

use App\Models\CanonicalJob;
use App\Models\JobSource;
use App\Services\Jobs\DriverRegistry;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchJobSource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $jobSourceId)
    {
    }

    public function handle(DriverRegistry $registry): void
    {
        $source = JobSource::findOrFail($this->jobSourceId);
        if (!$source->is_enabled) {
            return;
        }

        $driver = $registry->driverFor($source->source_type);

        try {
            foreach ($driver->fetch($source) as $canonical) {
                $existing = CanonicalJob::where('source', $source->source_type)
                    ->where('source_id', $canonical->sourceId)
                    ->first();
                $descriptionBefore = $existing?->description;

                $job = CanonicalJob::updateOrCreate(
                    ['source' => $source->source_type, 'source_id' => $canonical->sourceId],
                    $canonical->toEloquentAttributes($source->source_type),
                );

                if ($job->wasRecentlyCreated || $descriptionBefore !== $canonical->description) {
                    EmbedJob::dispatch($job->id);
                }
            }
            $source->update(['last_fetched_at' => now(), 'last_error' => null, 'consecutive_failures' => 0]);
        } catch (DriverFetchFailed $e) {
            $failures = $source->consecutive_failures + 1;
            $source->update([
                'last_error' => $e->getMessage(),
                'consecutive_failures' => $failures,
                'is_enabled' => $failures < 3,
            ]);
        }
    }

}
```

- [ ] **Step 5: Implement `EmbedJob`**

`app/Jobs/EmbedJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\CanonicalJob;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\ProviderRateLimited;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class EmbedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $canonicalJobId)
    {
    }

    public function handle(AiClient $ai): void
    {
        $job = CanonicalJob::find($this->canonicalJobId);
        if (!$job) {
            return;
        }

        try {
            $vector = $ai->forUser(null)->embed($job->description, 'job');
        } catch (ProviderRateLimited $e) {
            $this->release($e->retryAfterSeconds);
            return;
        }

        DB::statement('UPDATE canonical_jobs SET embedding = ?::vector WHERE id = ?', [
            '[' . implode(',', $vector) . ']',
            $job->id,
        ]);

        FanOutStage1Matches::dispatch($job->id);
    }
}
```

- [ ] **Step 6: Implement `FanOutStage1Matches` as a stub**

`app/Jobs/FanOutStage1Matches.php` (real body in Task 12):

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FanOutStage1Matches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $canonicalJobId)
    {
    }

    public function handle(): void
    {
        // Real body lands in Plan 2 Task 12.
    }
}
```

- [ ] **Step 7: Implement `CloseStaleJobs`**

`app/Jobs/CloseStaleJobs.php`:

```php
<?php

namespace App\Jobs;

use App\Models\CanonicalJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CloseStaleJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        CanonicalJob::whereNull('closed_at')
            ->where('last_seen_at', '<', now()->subDays(30))
            ->update(['closed_at' => now()]);
    }
}
```

- [ ] **Step 8: Wire the scheduler**

Edit `routes/console.php`:

```php
<?php

use App\Jobs\CloseStaleJobs;
use App\Jobs\FetchJobSource;
use App\Models\JobSource;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    JobSource::where('is_enabled', true)
        ->where(function ($q) {
            $q->whereNull('last_fetched_at')
                ->orWhereRaw('last_fetched_at < now() - (fetch_cadence_minutes * interval \'1 minute\')');
        })
        ->each(fn ($source) => FetchJobSource::dispatch($source->id));
})->everyMinute()->name('throughline-ingestion-tick')->withoutOverlapping();

Schedule::job(new CloseStaleJobs())->daily()->name('throughline-close-stale')->withoutOverlapping();
```

- [ ] **Step 9: Run the two tests to confirm pass**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=FetchJobSourceTest --filter=EmbedJobTest
```

Expected: both pass.

- [ ] **Step 10: Format + commit**

```bash
vendor/bin/pint --dirty
git add app/Jobs routes/console.php database/factories/CanonicalJobFactory.php tests/Feature/Jobs
git commit -m "$(cat <<'EOF'
feat: FetchJobSource + EmbedJob queued pipeline + scheduler

FetchJobSource resolves the driver via DriverRegistry, iterates the
streaming generator, upserts canonical_jobs on (source, source_id),
and dispatches EmbedJob for new or description-changed rows.
Consecutive-failure counter auto-pauses a source after three strikes;
a successful fetch resets it.

EmbedJob calls AiClient::forUser(null)->embed (system-attributed, no
user ceiling) and persists the vector via raw DB::statement, then
hands off to a FanOutStage1Matches stub that Task 12 fleshes out.
CloseStaleJobs closes jobs unseen for 30 days, scheduled daily.

Scheduler in routes/console.php dispatches FetchJobSource every
minute for any enabled source whose last_fetched_at is older than
its fetch_cadence_minutes.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 12: `job_matches` schema + `FanOutStage1Matches` + `RecomputeStage1ForResume`

**Files:**
- Create: `database/migrations/2026_04_21_100600_create_job_matches_table.php`
- Create: `app/Models/JobMatch.php`
- Modify: `app/Jobs/FanOutStage1Matches.php`
- Modify: `app/Jobs/RecomputeStage1ForResume.php`
- Create: `app/Services/Matching/HardFilterBuilder.php`
- Create: `tests/Feature/Matching/FanOutStage1MatchesTest.php`
- Create: `tests/Feature/Matching/HardFilterBuilderTest.php`

- [ ] **Step 1: Write the migration**

`database/migrations/2026_04_21_100600_create_job_matches_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('job_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('search_profile_id')->constrained('search_profiles')->cascadeOnDelete();
            $table->foreignId('canonical_job_id')->constrained('canonical_jobs')->cascadeOnDelete();
            $table->float('stage1_score');
            $table->integer('stage2_score')->nullable();
            $table->text('stage2_rationale')->nullable();
            $table->jsonb('stage2_gaps')->nullable();
            $table->text('stage2_narrative')->nullable();
            $table->timestamp('stage2_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['search_profile_id', 'canonical_job_id']);
            $table->index(['user_id', 'dismissed_at', 'stage1_score']);
            $table->index(['search_profile_id', 'stage1_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_matches');
    }
};
```

- [ ] **Step 2: Write the `JobMatch` model**

`app/Models/JobMatch.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobMatch extends Model
{
    public $timestamps = false;

    protected $table = 'job_matches';

    protected $fillable = [
        'user_id',
        'search_profile_id',
        'canonical_job_id',
        'stage1_score',
        'dismissed_at',
        'created_at',
    ];

    protected $casts = [
        'stage1_score' => 'float',
        'stage2_gaps' => 'array',
        'stage2_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(SearchProfile::class, 'search_profile_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(CanonicalJob::class, 'canonical_job_id');
    }
}
```

- [ ] **Step 3: Write the `HardFilterBuilder` test**

`tests/Feature/Matching/HardFilterBuilderTest.php`:

```php
<?php

namespace Tests\Feature\Matching;

use App\Models\CanonicalJob;
use App\Models\SearchProfile;
use App\Models\User;
use App\Services\Matching\HardFilterBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardFilterBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_remote_only_excludes_onsite(): void
    {
        $user = User::factory()->create();
        $profile = SearchProfile::factory()->for($user)->create(['remote_mode' => 'remote_only']);
        CanonicalJob::factory()->create(['remote_mode' => 'onsite', 'title' => 'onsite-a']);
        CanonicalJob::factory()->create(['remote_mode' => 'remote_only', 'title' => 'remote-a']);

        $ids = (new HardFilterBuilder($profile))->toQuery()->pluck('title')->all();
        $this->assertSame(['remote-a'], $ids);
    }

    public function test_comp_floor_excludes_below(): void
    {
        $user = User::factory()->create();
        $profile = SearchProfile::factory()->for($user)->create([
            'comp_min' => 200000, 'comp_currency' => 'USD', 'remote_mode' => 'any',
        ]);
        CanonicalJob::factory()->create(['comp_min' => 100000, 'comp_currency' => 'USD', 'title' => 'low']);
        CanonicalJob::factory()->create(['comp_min' => 250000, 'comp_currency' => 'USD', 'title' => 'high']);
        CanonicalJob::factory()->create(['comp_min' => null,    'comp_currency' => null,  'title' => 'nullcomp']);

        $titles = (new HardFilterBuilder($profile))->toQuery()->pluck('title')->all();
        $this->assertContains('high', $titles);
        $this->assertContains('nullcomp', $titles, 'jobs without comp are included by default');
        $this->assertNotContains('low', $titles);
    }

    public function test_excluded_companies_filter_by_ilike(): void
    {
        $user = User::factory()->create();
        $profile = SearchProfile::factory()->for($user)->create([
            'excluded_companies' => ['BigCo'], 'remote_mode' => 'any',
        ]);
        CanonicalJob::factory()->create(['company_name' => 'BigCo Inc', 'title' => 'blocked']);
        CanonicalJob::factory()->create(['company_name' => 'Acme', 'title' => 'allowed']);

        $titles = (new HardFilterBuilder($profile))->toQuery()->pluck('title')->all();
        $this->assertSame(['allowed'], $titles);
    }
}
```

- [ ] **Step 4: Implement `HardFilterBuilder`**

`app/Services/Matching/HardFilterBuilder.php`:

```php
<?php

namespace App\Services\Matching;

use App\Models\CanonicalJob;
use App\Models\SearchProfile;
use Illuminate\Database\Eloquent\Builder;

class HardFilterBuilder
{
    public function __construct(protected SearchProfile $profile)
    {
    }

    public function toQuery(): Builder
    {
        $q = CanonicalJob::query()->whereNull('closed_at');

        $mode = $this->profile->remote_mode;
        if ($mode === 'remote_only') {
            $q->where('remote_mode', 'remote_only');
        } elseif ($mode === 'onsite') {
            $q->where('remote_mode', 'onsite');
        } elseif ($mode === 'hybrid_ok') {
            $q->whereIn('remote_mode', ['remote_only', 'hybrid_ok']);
        }

        if ($this->profile->comp_min) {
            $q->where(function ($w) {
                $w->whereNull('comp_min')->orWhere('comp_min', '>=', $this->profile->comp_min);
            });
        }

        foreach ($this->profile->excluded_companies ?? [] as $excluded) {
            $q->where('company_name', 'not ilike', '%' . $excluded . '%');
        }

        return $q;
    }
}
```

- [ ] **Step 5: Run `HardFilterBuilderTest`**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=HardFilterBuilderTest
```

Expected: all tests pass.

- [ ] **Step 6: Write the `FanOutStage1Matches` test**

`tests/Feature/Matching/FanOutStage1MatchesTest.php`:

```php
<?php

namespace Tests\Feature\Matching;

use App\Jobs\FanOutStage1Matches;
use App\Models\CanonicalJob;
use App\Models\JobMatch;
use App\Models\Resume;
use App\Models\SearchProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FanOutStage1MatchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_match_when_profile_resume_and_job_have_embeddings(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->parsed()->create();
        $profile = SearchProfile::factory()->for($user)->create(['resume_id' => $resume->id, 'remote_mode' => 'any']);
        $job = CanonicalJob::factory()->create(['remote_mode' => 'remote_only']);

        $this->writeVector('resumes', $resume->id, array_fill(0, 1536, 0.5));
        $this->writeVector('canonical_jobs', $job->id, array_fill(0, 1536, 0.5));

        (new FanOutStage1Matches($job->id))->handle();

        $this->assertSame(1, JobMatch::count());
        $match = JobMatch::first();
        $this->assertSame($profile->id, $match->search_profile_id);
        $this->assertGreaterThan(0.9, $match->stage1_score, 'identical vectors produce similarity ~ 1.0');
    }

    public function test_skips_profile_with_no_resume(): void
    {
        $user = User::factory()->create();
        SearchProfile::factory()->for($user)->create(['resume_id' => null]);
        $job = CanonicalJob::factory()->create();
        $this->writeVector('canonical_jobs', $job->id, array_fill(0, 1536, 0.1));

        (new FanOutStage1Matches($job->id))->handle();

        $this->assertSame(0, JobMatch::count());
    }

    public function test_respects_hard_filter_and_score_floor(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->parsed()->create();
        $profile = SearchProfile::factory()->for($user)->create([
            'resume_id' => $resume->id,
            'remote_mode' => 'remote_only',
        ]);
        $onsite = CanonicalJob::factory()->create(['remote_mode' => 'onsite']);
        $remote = CanonicalJob::factory()->create(['remote_mode' => 'remote_only']);

        $this->writeVector('resumes', $resume->id, array_fill(0, 1536, 0.5));
        $this->writeVector('canonical_jobs', $onsite->id, array_fill(0, 1536, 0.5));
        $this->writeVector('canonical_jobs', $remote->id, array_fill(0, 1536, 0.5));

        (new FanOutStage1Matches($onsite->id))->handle();
        (new FanOutStage1Matches($remote->id))->handle();

        $this->assertSame(1, JobMatch::count());
        $this->assertSame($remote->id, JobMatch::first()->canonical_job_id);
    }

    protected function writeVector(string $table, int $id, array $vec): void
    {
        DB::statement("UPDATE {$table} SET embedding = ?::vector WHERE id = ?", [
            '[' . implode(',', $vec) . ']',
            $id,
        ]);
    }
}
```

- [ ] **Step 7: Run — expect fail**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=FanOutStage1MatchesTest
```

Expected: FAIL.

- [ ] **Step 8: Implement `FanOutStage1Matches`**

Overwrite `app/Jobs/FanOutStage1Matches.php`:

```php
<?php

namespace App\Jobs;

use App\Models\CanonicalJob;
use App\Models\SearchProfile;
use App\Services\Matching\HardFilterBuilder;
use App\Services\SiteSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FanOutStage1Matches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $canonicalJobId)
    {
    }

    public function handle(): void
    {
        $job = CanonicalJob::find($this->canonicalJobId);
        if (!$job) {
            return;
        }

        $jobEmbedding = DB::table('canonical_jobs')->where('id', $job->id)->value('embedding');
        if (!$jobEmbedding) {
            return; // no embedding yet — EmbedJob will re-dispatch us
        }

        $floor = (float) app(SiteSettings::class)->get('stage1_score_floor', '0.2');

        SearchProfile::where('is_active', true)->whereNotNull('resume_id')->chunkById(100, function ($profiles) use ($job, $jobEmbedding, $floor) {
            foreach ($profiles as $profile) {
                try {
                    $this->fanOutOne($profile, $job, $jobEmbedding, $floor);
                } catch (\Throwable $e) {
                    Log::warning('FanOutStage1Matches profile failure', [
                        'profile_id' => $profile->id,
                        'canonical_job_id' => $job->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    protected function fanOutOne(SearchProfile $profile, CanonicalJob $job, string $jobEmbedding, float $floor): void
    {
        $resumeEmbedding = DB::table('resumes')->where('id', $profile->resume_id)->value('embedding');
        if (!$resumeEmbedding) {
            return;
        }

        $passesHardFilters = (new HardFilterBuilder($profile))
            ->toQuery()
            ->where('id', $job->id)
            ->exists();
        if (!$passesHardFilters) {
            return;
        }

        $similarity = DB::selectOne(
            'SELECT 1 - (?::vector <=> ?::vector) AS s',
            [$resumeEmbedding, $jobEmbedding],
        )->s;

        if ($similarity < $floor) {
            return;
        }

        DB::table('job_matches')->upsert([
            [
                'user_id' => $profile->user_id,
                'search_profile_id' => $profile->id,
                'canonical_job_id' => $job->id,
                'stage1_score' => $similarity,
                'created_at' => now(),
            ],
        ], ['search_profile_id', 'canonical_job_id'], ['stage1_score']);
    }
}
```

- [ ] **Step 9: Implement real `RecomputeStage1ForResume`**

Overwrite `app/Jobs/RecomputeStage1ForResume.php`:

```php
<?php

namespace App\Jobs;

use App\Models\CanonicalJob;
use App\Models\SearchProfile;
use App\Services\Matching\HardFilterBuilder;
use App\Services\SiteSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecomputeStage1ForResume implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $resumeId)
    {
    }

    public function handle(): void
    {
        $resumeEmbedding = DB::table('resumes')->where('id', $this->resumeId)->value('embedding');
        if (!$resumeEmbedding) {
            return;
        }

        $floor = (float) app(SiteSettings::class)->get('stage1_score_floor', '0.2');

        SearchProfile::where('is_active', true)->where('resume_id', $this->resumeId)->each(function (SearchProfile $profile) use ($resumeEmbedding, $floor) {
            try {
                DB::table('job_matches')->where('search_profile_id', $profile->id)->delete();

                (new HardFilterBuilder($profile))->toQuery()
                    ->whereNotNull('embedding')
                    ->chunkById(200, function ($jobs) use ($profile, $resumeEmbedding, $floor) {
                        foreach ($jobs as $job) {
                            $jobEmbedding = DB::table('canonical_jobs')->where('id', $job->id)->value('embedding');
                            if (!$jobEmbedding) continue;

                            $similarity = DB::selectOne(
                                'SELECT 1 - (?::vector <=> ?::vector) AS s',
                                [$resumeEmbedding, $jobEmbedding],
                            )->s;

                            if ($similarity < $floor) continue;

                            DB::table('job_matches')->insert([
                                'user_id' => $profile->user_id,
                                'search_profile_id' => $profile->id,
                                'canonical_job_id' => $job->id,
                                'stage1_score' => $similarity,
                                'created_at' => now(),
                            ]);
                        }
                    });
            } catch (\Throwable $e) {
                Log::warning('RecomputeStage1ForResume profile failure', [
                    'profile_id' => $profile->id,
                    'resume_id' => $this->resumeId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
```

- [ ] **Step 10: Run the migrations + tests**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan migrate --no-interaction
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=Matching
```

Expected: all matching tests pass.

- [ ] **Step 11: Format + commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_04_21_100600_create_job_matches_table.php app/Models/JobMatch.php app/Services/Matching app/Jobs/FanOutStage1Matches.php app/Jobs/RecomputeStage1ForResume.php tests/Feature/Matching
git commit -m "$(cat <<'EOF'
feat: stage-1 matching — job_matches schema + fan-out + recompute

job_matches unique on (search_profile_id, canonical_job_id) makes
FanOutStage1Matches upsertable. Stage-2 columns already nullable
for Plan 3's rerank output. HardFilterBuilder composes the
remote-mode, comp-floor, and excluded-company SQL filters into a
reusable Eloquent builder used by both fan-out paths.

FanOutStage1Matches (per-job) iterates active profiles with a linked
resume, computes cosine similarity via pgvector's <=> operator,
inserts a row above the stage1_score_floor. Per-profile errors log
and continue rather than aborting the loop.

RecomputeStage1ForResume (per-resume) deletes existing match rows
and reinserts by iterating filtered jobs. Plan 3 will revisit the
delete-and-reinsert pattern to preserve rerank output.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 13: Feed controller + card component + rationale slot

**Files:**
- Create: `app/Http/Controllers/FeedController.php`
- Modify: `routes/web.php`
- Create: `resources/js/pages/feed/Show.vue`
- Create: `resources/js/components/feed/JobMatchCard.vue`
- Create: `resources/js/components/feed/RationaleSlot.vue`
- Create: `tests/Feature/Feed/FeedTest.php`

- [ ] **Step 1: Write the feed test**

`tests/Feature/Feed/FeedTest.php`:

```php
<?php

namespace Tests\Feature\Feed;

use App\Models\CanonicalJob;
use App\Models\JobMatch;
use App\Models\Resume;
use App\Models\SearchProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_lists_matches_in_stage1_score_order(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->parsed()->create();
        $profile = SearchProfile::factory()->for($user)->create(['resume_id' => $resume->id]);

        $jobA = CanonicalJob::factory()->create(['title' => 'A', 'company_name' => 'AAA']);
        $jobB = CanonicalJob::factory()->create(['title' => 'B', 'company_name' => 'BBB']);
        $jobC = CanonicalJob::factory()->create(['title' => 'C', 'company_name' => 'CCC']);

        JobMatch::insert([
            ['user_id' => $user->id, 'search_profile_id' => $profile->id, 'canonical_job_id' => $jobA->id, 'stage1_score' => 0.40, 'created_at' => now()],
            ['user_id' => $user->id, 'search_profile_id' => $profile->id, 'canonical_job_id' => $jobB->id, 'stage1_score' => 0.80, 'created_at' => now()],
            ['user_id' => $user->id, 'search_profile_id' => $profile->id, 'canonical_job_id' => $jobC->id, 'stage1_score' => 0.60, 'created_at' => now()],
        ]);

        $response = $this->actingAs($user)->get(route('feed.show', $profile));

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $titles = collect($props['matches']['data'])->pluck('job.title')->all();
        $this->assertSame(['B', 'C', 'A'], $titles);
    }

    public function test_feed_excludes_dismissed_matches(): void
    {
        $user = User::factory()->create();
        $profile = SearchProfile::factory()->for($user)->create();
        $job = CanonicalJob::factory()->create();
        JobMatch::insert([
            ['user_id' => $user->id, 'search_profile_id' => $profile->id, 'canonical_job_id' => $job->id, 'stage1_score' => 0.5, 'dismissed_at' => now(), 'created_at' => now()],
        ]);

        $response = $this->actingAs($user)->get(route('feed.show', $profile));

        $props = $response->viewData('page')['props'];
        $this->assertCount(0, $props['matches']['data']);
    }

    public function test_feed_forbids_other_users_profiles(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $profile = SearchProfile::factory()->for($owner)->create();

        $this->actingAs($stranger)->get(route('feed.show', $profile))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the test — expect fail**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=FeedTest
```

Expected: FAIL — route and controller missing.

- [ ] **Step 3: Implement `FeedController`**

`app/Http/Controllers/FeedController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\SearchProfile;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeedController extends Controller
{
    public function show(Request $request, SearchProfile $profile): Response
    {
        abort_unless($profile->user_id === $request->user()->id, 403);

        $matches = $profile->load('resume:id,name');
        $page = \DB::table('job_matches')
            ->join('canonical_jobs', 'job_matches.canonical_job_id', '=', 'canonical_jobs.id')
            ->where('job_matches.search_profile_id', $profile->id)
            ->whereNull('job_matches.dismissed_at')
            ->orderByDesc('job_matches.stage1_score')
            ->select([
                'job_matches.id as match_id',
                'job_matches.stage1_score',
                'canonical_jobs.id as job_id',
                'canonical_jobs.title',
                'canonical_jobs.company_name',
                'canonical_jobs.location_raw',
                'canonical_jobs.remote_mode',
                'canonical_jobs.comp_min',
                'canonical_jobs.comp_max',
                'canonical_jobs.comp_currency',
                'canonical_jobs.posted_at',
                'canonical_jobs.apply_url',
            ])
            ->paginate(20);

        $data = $page->through(fn ($row) => [
            'match_id' => $row->match_id,
            'stage1_score' => (float) $row->stage1_score,
            'job' => [
                'id' => $row->job_id,
                'title' => $row->title,
                'company_name' => $row->company_name,
                'location_raw' => $row->location_raw,
                'remote_mode' => $row->remote_mode,
                'comp_min' => $row->comp_min,
                'comp_max' => $row->comp_max,
                'comp_currency' => $row->comp_currency,
                'posted_at' => $row->posted_at,
                'apply_url' => $row->apply_url,
            ],
        ]);

        return Inertia::render('feed/Show', [
            'profile' => [
                'id' => $profile->id,
                'name' => $profile->name,
                'resume' => $profile->resume ? ['id' => $profile->resume->id, 'name' => $profile->resume->name] : null,
            ],
            'matches' => $data,
            'showDebug' => $request->user()->isAdmin() && $request->boolean('debug'),
        ]);
    }
}
```

- [ ] **Step 4: Wire the route**

Edit `routes/web.php`, inside the auth group:

```php
use App\Http\Controllers\FeedController;
Route::get('/feed/{profile}', [FeedController::class, 'show'])->name('feed.show');
```

- [ ] **Step 5: Write `resources/js/components/feed/RationaleSlot.vue`**

```vue
<script setup lang="ts">
defineProps<{
  match: Record<string, any>;
  showDebug?: boolean;
}>();
</script>

<template>
  <div class="mt-4 rounded border border-dashed border-hairline bg-bone-2/60 px-4 py-3 text-sm text-ink-3">
    <p class="font-medium text-ink-2">AI rationale pending</p>
    <p class="mt-1">Career-arc rationale, gaps, and narrative framing ship in Plan 3 — this is where they will render.</p>
    <p v-if="showDebug" class="mt-2 font-mono text-xs">stage1_score = {{ match.stage1_score.toFixed(4) }}</p>
  </div>
</template>
```

- [ ] **Step 6: Write `resources/js/components/feed/JobMatchCard.vue`**

```vue
<script setup lang="ts">
import RationaleSlot from './RationaleSlot.vue';

defineProps<{
  match: {
    match_id: number;
    stage1_score: number;
    job: {
      id: number;
      title: string;
      company_name: string;
      location_raw: string | null;
      remote_mode: string;
      comp_min: number | null;
      comp_max: number | null;
      comp_currency: string | null;
      posted_at: string | null;
      apply_url: string;
    };
  };
  showDebug?: boolean;
}>();

function formatComp(min: number | null, max: number | null, currency: string | null) {
  if (!min && !max) return null;
  const cur = currency ?? 'USD';
  if (min && max) return `${cur} ${Math.round(min / 1000)}k–${Math.round(max / 1000)}k`;
  return `${cur} ${Math.round((min ?? max ?? 0) / 1000)}k+`;
}

function remoteBadge(mode: string) {
  return {
    remote_only: 'Remote',
    hybrid_ok: 'Hybrid OK',
    onsite: 'On-site',
    any: '—',
  }[mode] ?? mode;
}
</script>

<template>
  <article class="rounded-lg border border-hairline bg-bone px-6 py-5 shadow-sm">
    <header class="flex flex-wrap items-baseline justify-between gap-2">
      <div>
        <h3 class="font-display text-xl text-ink">{{ match.job.title }}</h3>
        <p class="text-sm text-ink-2">{{ match.job.company_name }}</p>
      </div>
      <a :href="match.job.apply_url" target="_blank" rel="noopener" class="text-sm text-oxblood hover:underline">
        View posting &rarr;
      </a>
    </header>

    <dl class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-3">
      <div v-if="match.job.location_raw"><dt class="sr-only">Location</dt><dd>{{ match.job.location_raw }}</dd></div>
      <div><dt class="sr-only">Mode</dt><dd>{{ remoteBadge(match.job.remote_mode) }}</dd></div>
      <div v-if="formatComp(match.job.comp_min, match.job.comp_max, match.job.comp_currency)">
        <dt class="sr-only">Compensation</dt><dd>{{ formatComp(match.job.comp_min, match.job.comp_max, match.job.comp_currency) }}</dd>
      </div>
      <div v-if="match.job.posted_at">
        <dt class="sr-only">Posted</dt><dd>Posted {{ new Date(match.job.posted_at).toLocaleDateString() }}</dd>
      </div>
    </dl>

    <RationaleSlot :match="match" :show-debug="showDebug" />
  </article>
</template>
```

- [ ] **Step 7: Write `resources/js/pages/feed/Show.vue`**

```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import JobMatchCard from '@/components/feed/JobMatchCard.vue';

defineProps<{
  profile: { id: number; name: string; resume: { id: number; name: string } | null };
  matches: { data: any[]; links: any[]; meta?: any };
  showDebug: boolean;
}>();
</script>

<template>
  <Head :title="`Feed — ${profile.name}`" />
  <AppLayout>
    <div class="mx-auto max-w-3xl px-6 py-10">
      <Link :href="route('search-profiles.index')" class="text-sm text-ink-3 hover:text-ink">&larr; All profiles</Link>
      <header class="mt-4">
        <h1 class="font-display text-4xl text-ink">{{ profile.name }}</h1>
        <p v-if="profile.resume" class="mt-1 text-sm text-ink-3">Matched against {{ profile.resume.name }}.</p>
        <p v-else class="mt-1 text-sm text-ink-3">No linked resume — matches can't generate until you link one.</p>
      </header>

      <section v-if="matches.data.length" class="mt-8 space-y-4">
        <JobMatchCard v-for="m in matches.data" :key="m.match_id" :match="m" :show-debug="showDebug" />
      </section>

      <div v-else class="mt-10 rounded-lg border border-hairline bg-bone-2 px-6 py-12 text-center">
        <p class="text-ink-2">No matches yet.</p>
        <p class="mt-1 text-sm text-ink-3">Matches appear once your resume is embedded and a drive job fetch has run.</p>
      </div>
    </div>
  </AppLayout>
</template>
```

- [ ] **Step 8: Run tests**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=FeedTest
```

Expected: all three tests pass.

- [ ] **Step 9: Format + commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/FeedController.php routes/web.php resources/js/pages/feed resources/js/components/feed tests/Feature/Feed
git commit -m "$(cat <<'EOF'
feat: feed UI with JobMatchCard and Plan-3-shaped rationale slot

FeedController queries job_matches joined to canonical_jobs, ordered
by stage1_score, paginated 20/page. Authorization pinned to the
profile owner.

JobMatchCard is the canonical card shell future plans extend.
RationaleSlot renders a placeholder state that Plan 3 replaces with
real Claude output; stage1_score is visible behind ?debug=1 when an
admin is viewing. Empty-feed state explains the likely cause
(no embedding yet, no fetch run yet) so users can self-diagnose.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 14: Admin job-sources CRUD + "fetch now"

**Files:**
- Create: `app/Http/Controllers/Admin/JobSourceController.php`
- Create: `app/Http/Requests/Admin/StoreJobSourceRequest.php`
- Create: `app/Http/Requests/Admin/UpdateJobSourceRequest.php`
- Modify: `routes/web.php` (admin group with `EnsureAdmin` middleware)
- Create: `resources/js/pages/admin/job-sources/Index.vue`
- Create: `resources/js/pages/admin/job-sources/Edit.vue`
- Create: `tests/Feature/Admin/JobSourceControllerTest.php`

- [ ] **Step 1: Write the store/update form requests**

`app/Http/Requests/Admin/StoreJobSourceRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use App\Services\Jobs\DriverRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        $registry = app(DriverRegistry::class);

        return [
            'source_type' => ['required', Rule::in($registry->supportedTypes())],
            'identifier' => ['nullable', 'string', 'max:120'],
            'display_name' => ['required', 'string', 'max:160'],
            'fetch_cadence_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'is_enabled' => ['boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $registry = app(DriverRegistry::class);
            $driver = $registry->driverFor($this->input('source_type'));
            if (!$driver->validateIdentifier($this->input('identifier'))) {
                $v->errors()->add('identifier', 'Identifier is required for this source type.');
            }
        });
    }
}
```

`app/Http/Requests/Admin/UpdateJobSourceRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

class UpdateJobSourceRequest extends StoreJobSourceRequest
{
    // Same rules and authorize as store.
}
```

- [ ] **Step 2: Write the controller**

`app/Http/Controllers/Admin/JobSourceController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreJobSourceRequest;
use App\Http\Requests\Admin\UpdateJobSourceRequest;
use App\Jobs\FetchJobSource;
use App\Models\JobSource;
use App\Services\Jobs\DriverRegistry;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class JobSourceController extends Controller
{
    public function index(DriverRegistry $registry): Response
    {
        return Inertia::render('admin/job-sources/Index', [
            'sources' => JobSource::orderBy('source_type')->orderBy('identifier')->get(),
            'driverTypes' => $registry->supportedTypes(),
        ]);
    }

    public function create(DriverRegistry $registry): Response
    {
        return Inertia::render('admin/job-sources/Edit', [
            'source' => new JobSource(['source_type' => $registry->supportedTypes()[0], 'fetch_cadence_minutes' => 60, 'is_enabled' => true]),
            'driverTypes' => $registry->supportedTypes(),
        ]);
    }

    public function edit(JobSource $job_source, DriverRegistry $registry): Response
    {
        return Inertia::render('admin/job-sources/Edit', [
            'source' => $job_source,
            'driverTypes' => $registry->supportedTypes(),
        ]);
    }

    public function store(StoreJobSourceRequest $request): RedirectResponse
    {
        JobSource::create($request->validated() + ['consecutive_failures' => 0]);
        return redirect()->route('admin.job-sources.index')->with('status', 'Source created.');
    }

    public function update(UpdateJobSourceRequest $request, JobSource $job_source): RedirectResponse
    {
        $job_source->update($request->validated());
        return redirect()->route('admin.job-sources.index')->with('status', 'Source updated.');
    }

    public function destroy(JobSource $job_source): RedirectResponse
    {
        $job_source->delete();
        return redirect()->route('admin.job-sources.index')->with('status', 'Source deleted.');
    }

    public function fetchNow(JobSource $job_source): RedirectResponse
    {
        FetchJobSource::dispatch($job_source->id);
        return back()->with('status', "Fetch queued for {$job_source->display_name}.");
    }
}
```

- [ ] **Step 3: Wire routes and the admin middleware**

Edit `routes/web.php`. Append, outside the regular auth group:

```php
use App\Http\Controllers\Admin\JobSourceController;

Route::middleware(['auth', \App\Http\Middleware\EnsureAdmin::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::resource('job-sources', JobSourceController::class)->except('show');
        Route::post('job-sources/{job_source}/fetch-now', [JobSourceController::class, 'fetchNow'])->name('job-sources.fetch-now');
    });
```

If `App\Http\Middleware\EnsureAdmin` doesn't yet exist (Plan 1 may have shipped it — verify), create it:

`app/Http/Middleware/EnsureAdmin.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user() || ! $request->user()->isAdmin()) {
            abort(403);
        }
        return $next($request);
    }
}
```

- [ ] **Step 4: Write the feature test**

`tests/Feature/Admin/JobSourceControllerTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Jobs\FetchJobSource;
use App\Models\JobSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class JobSourceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->actingAs($user)->get(route('admin.job-sources.index'))->assertForbidden();
    }

    public function test_admin_can_create_greenhouse_source(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.job-sources.store'), [
            'source_type' => 'greenhouse',
            'identifier' => 'acme',
            'display_name' => 'Acme (Greenhouse)',
            'fetch_cadence_minutes' => 30,
            'is_enabled' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('job_sources', [
            'source_type' => 'greenhouse',
            'identifier' => 'acme',
        ]);
    }

    public function test_greenhouse_source_requires_identifier(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.job-sources.store'), [
            'source_type' => 'greenhouse',
            'identifier' => '',
            'display_name' => 'Bad',
            'fetch_cadence_minutes' => 30,
        ])->assertSessionHasErrors('identifier');
    }

    public function test_remoteok_source_accepts_null_identifier(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.job-sources.store'), [
            'source_type' => 'remoteok',
            'identifier' => null,
            'display_name' => 'RemoteOK',
            'fetch_cadence_minutes' => 120,
        ])->assertRedirect();

        $this->assertDatabaseHas('job_sources', ['source_type' => 'remoteok', 'identifier' => null]);
    }

    public function test_fetch_now_dispatches_job(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $source = JobSource::factory()->create(['source_type' => 'greenhouse', 'identifier' => 'stripe']);

        $this->actingAs($admin)->post(route('admin.job-sources.fetch-now', $source))->assertRedirect();

        Queue::assertPushed(FetchJobSource::class, fn ($j) => $j->jobSourceId === $source->id);
    }
}
```

- [ ] **Step 5: Write the Vue pages**

`resources/js/pages/admin/job-sources/Index.vue`:

```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps<{
  sources: Array<{
    id: number;
    source_type: string;
    identifier: string | null;
    display_name: string;
    is_enabled: boolean;
    fetch_cadence_minutes: number;
    last_fetched_at: string | null;
    last_error: string | null;
    consecutive_failures: number;
  }>;
  driverTypes: string[];
}>();

function fetchNow(id: number) {
  router.post(route('admin.job-sources.fetch-now', id));
}

function destroy(id: number) {
  if (window.confirm('Delete this source? Existing canonical_jobs are kept.')) {
    router.delete(route('admin.job-sources.destroy', id));
  }
}
</script>

<template>
  <Head title="Admin — job sources" />
  <AppLayout>
    <div class="mx-auto max-w-5xl px-6 py-10">
      <header class="mb-8 flex items-end justify-between">
        <h1 class="font-display text-4xl text-ink">Job sources</h1>
        <Link :href="route('admin.job-sources.create')" class="rounded-full bg-oxblood px-5 py-2 text-sm font-medium text-bone">New source</Link>
      </header>

      <table class="w-full border-collapse text-sm">
        <thead class="text-left text-ink-3">
          <tr>
            <th class="py-2 pr-3">Name</th>
            <th class="py-2 pr-3">Type</th>
            <th class="py-2 pr-3">Identifier</th>
            <th class="py-2 pr-3">Cadence</th>
            <th class="py-2 pr-3">Last fetched</th>
            <th class="py-2 pr-3">Status</th>
            <th class="py-2"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-hairline">
          <tr v-for="s in sources" :key="s.id">
            <td class="py-3 pr-3">
              <Link :href="route('admin.job-sources.edit', s.id)" class="text-ink hover:text-oxblood">{{ s.display_name }}</Link>
            </td>
            <td class="py-3 pr-3 text-ink-3">{{ s.source_type }}</td>
            <td class="py-3 pr-3 text-ink-3 font-mono text-xs">{{ s.identifier ?? '—' }}</td>
            <td class="py-3 pr-3 text-ink-3">{{ s.fetch_cadence_minutes }}m</td>
            <td class="py-3 pr-3 text-ink-3">{{ s.last_fetched_at ? new Date(s.last_fetched_at).toLocaleString() : 'never' }}</td>
            <td class="py-3 pr-3">
              <span v-if="!s.is_enabled" class="text-oxblood">Paused</span>
              <span v-else-if="s.last_error" class="text-oxblood" :title="s.last_error">Error ({{ s.consecutive_failures }})</span>
              <span v-else class="text-ink-3">OK</span>
            </td>
            <td class="py-3 text-right space-x-3">
              <button class="text-xs text-ink-3 hover:text-oxblood" @click="fetchNow(s.id)">Fetch now</button>
              <button class="text-xs text-ink-3 hover:text-oxblood" @click="destroy(s.id)">Delete</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </AppLayout>
</template>
```

`resources/js/pages/admin/job-sources/Edit.vue`:

```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
  source: {
    id?: number;
    source_type: string;
    identifier: string | null;
    display_name: string;
    fetch_cadence_minutes: number;
    is_enabled: boolean;
    last_error?: string | null;
  };
  driverTypes: string[];
}>();

const isEditing = computed(() => !!props.source.id);

const form = useForm({
  source_type: props.source.source_type,
  identifier: props.source.identifier,
  display_name: props.source.display_name,
  fetch_cadence_minutes: props.source.fetch_cadence_minutes,
  is_enabled: props.source.is_enabled,
});

function submit() {
  if (isEditing.value) {
    form.put(route('admin.job-sources.update', props.source.id));
  } else {
    form.post(route('admin.job-sources.store'));
  }
}
</script>

<template>
  <Head :title="isEditing ? 'Edit source' : 'New source'" />
  <AppLayout>
    <div class="mx-auto max-w-xl px-6 py-10">
      <Link :href="route('admin.job-sources.index')" class="text-sm text-ink-3 hover:text-ink">&larr; All sources</Link>
      <h1 class="mt-4 font-display text-4xl text-ink">{{ isEditing ? source.display_name : 'New source' }}</h1>

      <p v-if="source.last_error" class="mt-4 rounded border border-oxblood bg-oxblood/10 px-4 py-3 text-sm text-oxblood">
        Last error: {{ source.last_error }}
      </p>

      <form class="mt-6 space-y-5" @submit.prevent="submit">
        <div>
          <label class="block text-sm text-ink-2">Source type</label>
          <select v-model="form.source_type" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2">
            <option v-for="t in driverTypes" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm text-ink-2">Identifier (board slug / company / null for aggregators)</label>
          <input v-model="form.identifier" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" />
          <p v-if="form.errors.identifier" class="mt-1 text-sm text-oxblood">{{ form.errors.identifier }}</p>
        </div>
        <div>
          <label class="block text-sm text-ink-2">Display name</label>
          <input v-model="form.display_name" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" />
        </div>
        <div>
          <label class="block text-sm text-ink-2">Fetch cadence (minutes)</label>
          <input v-model.number="form.fetch_cadence_minutes" type="number" min="5" max="1440" class="mt-1 w-32 rounded border border-hairline bg-bone-2 px-3 py-2" />
        </div>
        <label class="inline-flex items-center gap-2">
          <input v-model="form.is_enabled" type="checkbox" />
          <span class="text-sm text-ink-2">Enabled</span>
        </label>
        <div class="flex justify-end">
          <button type="submit" class="rounded-full bg-oxblood px-5 py-2 text-sm font-medium text-bone" :disabled="form.processing">
            {{ isEditing ? 'Save' : 'Create source' }}
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
```

- [ ] **Step 6: Add the admin nav link**

Edit `resources/js/layouts/AppLayout.vue`. Inside the nav area, add (wrapped in a `v-if` that checks the authenticated user's role — either via the existing `$page.props.auth.user.role` or an equivalent page prop):

```vue
<Link v-if="$page.props.auth?.user?.role === 'admin'" :href="route('admin.job-sources.index')" class="text-sm text-oxblood hover:underline">Admin · Sources</Link>
```

- [ ] **Step 7: Run the admin tests**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=JobSourceControllerTest
```

Expected: all 5 tests pass.

- [ ] **Step 8: Format + commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Admin app/Http/Requests/Admin app/Http/Middleware/EnsureAdmin.php routes/web.php resources/js/pages/admin/job-sources resources/js/layouts/AppLayout.vue tests/Feature/Admin
git commit -m "$(cat <<'EOF'
feat: admin job-sources CRUD + fetch-now action

JobSourceController gates on EnsureAdmin middleware and exposes
index/create/store/edit/update/destroy plus a fetch-now action that
dispatches FetchJobSource synchronously. Form-request validator
delegates identifier-shape checks to the driver's
validateIdentifier() so RemoteOK's null identifier and Greenhouse's
required slug are both accepted by the same rule surface.

Index table renders display_name, type, identifier, cadence,
last_fetched_at, and a status column that flags pauses and errors.
Editor shows last_error inline when present so admins can see the
failure text without reading the DB.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 15: Playwright E2E smoke + docs updates + final verification

**Files:**
- Create: `playwright.config.ts`
- Create: `package.json` (modify — add `@playwright/test` and scripts)
- Create: `tests/e2e/feed-happy-path.spec.ts`
- Create: `routes/testing.php` (loaded only when `APP_ENV=testing`)
- Modify: `bootstrap/app.php` (conditional include of `routes/testing.php`)
- Create: `tests/Fixtures/resumes/sample.pdf` (small valid PDF — any human-readable resume snippet)
- Create: `app/Providers/TestingServiceProvider.php` (binds a fake `AiClient` when `APP_ENV=testing`)
- Modify: `CLAUDE.md`
- Modify: `README.md`
- Create: `tests/Feature/Brand/DriverHostsTest.php` (extends existing NoHardcodedHostsTest guard)

- [ ] **Step 1: Install Playwright**

```bash
npm install --save-dev @playwright/test
npx playwright install chromium
```

- [ ] **Step 2: Write `playwright.config.ts`**

`playwright.config.ts` at repo root:

```ts
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: 'tests/e2e',
  timeout: 60000,
  retries: 0,
  use: {
    baseURL: 'http://127.0.0.1:8000',
    headless: true,
    viewport: { width: 1280, height: 800 },
  },
  webServer: {
    command: 'php artisan serve --env=testing --port=8000',
    url: 'http://127.0.0.1:8000',
    reuseExistingServer: !process.env.CI,
    timeout: 30000,
  },
});
```

Append scripts to `package.json`:

```json
"scripts": {
  "test:e2e": "playwright test",
  "test:e2e:install": "playwright install chromium"
}
```

- [ ] **Step 3: Write the testing service provider**

`app/Providers/TestingServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Services\Ai\AiClient;
use Illuminate\Support\ServiceProvider;

class TestingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        $this->app->singleton(AiClient::class, function () {
            return new class extends AiClient {
                public function __construct() {}
                public function embed(string $text, string $context): array
                {
                    return array_fill(0, 1536, 0.5);
                }
                public function parseResume(string $rawText): array
                {
                    return [
                        'text' => $rawText,
                        'structured' => ['skills' => ['PHP'], 'titles' => ['Engineer']],
                    ];
                }
            };
        });
    }
}
```

Register it in `bootstrap/providers.php` (or `config/app.php`'s `providers` array if that's the active pattern in this Laravel install):

```php
App\Providers\TestingServiceProvider::class,
```

- [ ] **Step 4: Write the test-only route**

`routes/testing.php`:

```php
<?php

use App\Models\CanonicalJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('/testing/seed-jobs', function () {
    $jobs = [
        ['title' => 'Staff Frontend Engineer', 'company_name' => 'Acme', 'remote_mode' => 'remote_only'],
        ['title' => 'Senior Platform Engineer', 'company_name' => 'Beta',  'remote_mode' => 'hybrid_ok'],
        ['title' => 'Principal Engineer',       'company_name' => 'Gamma', 'remote_mode' => 'onsite'],
    ];
    foreach ($jobs as $attrs) {
        $job = CanonicalJob::create([
            'source' => 'test',
            'source_id' => 'seed-' . uniqid(),
            'source_board' => 'test',
            'company_name' => $attrs['company_name'],
            'title' => $attrs['title'],
            'description' => 'Seeded for E2E smoke.',
            'remote_mode' => $attrs['remote_mode'],
            'apply_url' => 'https://example.test/job/' . uniqid(),
            'posted_at' => now(),
            'last_seen_at' => now(),
        ]);
        DB::statement("UPDATE canonical_jobs SET embedding = ?::vector WHERE id = ?", [
            '[' . implode(',', array_fill(0, 1536, 0.5)) . ']',
            $job->id,
        ]);
    }
    return response()->json(['seeded' => count($jobs)]);
});

Route::post('/testing/run-matching/{profileId}', function ($profileId) {
    \App\Jobs\RecomputeStage1ForResume::dispatchSync(
        \App\Models\SearchProfile::findOrFail($profileId)->resume_id
    );
    return response()->json(['ok' => true]);
});
```

Include conditionally in `bootstrap/app.php` — in the `withRouting` closure, add:

```php
if (app()->environment('testing')) {
    $router->middleware('web')->group(base_path('routes/testing.php'));
}
```

- [ ] **Step 5: Write the E2E spec**

`tests/e2e/feed-happy-path.spec.ts`:

```ts
import { test, expect } from '@playwright/test';

test('user can sign up, upload resume, create profile, and see ranked feed', async ({ page, request }) => {
  const email = `e2e+${Date.now()}@example.test`;
  const password = 'password123';

  await page.goto('/register');
  await page.getByLabel('Name').fill('E2E User');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByLabel('Confirm Password').fill(password);
  await page.getByRole('button', { name: /register|create account/i }).click();
  await expect(page).toHaveURL(/dashboard|\//);

  // Upload a resume.
  await page.goto('/resumes');
  await page.getByRole('button', { name: /upload resume/i }).click();
  await page.getByLabel('Name').fill('Staff frontend');
  await page.setInputFiles('input[type=file]', 'tests/Fixtures/resumes/sample.pdf');
  await page.getByRole('button', { name: /upload/i }).click();

  // Wait for parse + embed (fake AiClient is instant in testing env).
  await expect(page.getByText(/ready to match/i)).toBeVisible({ timeout: 15000 });

  // Create search profile.
  await page.goto('/search-profiles/create');
  await page.getByLabel('Name').fill('Staff frontend search');
  await page.getByRole('button', { name: /create profile/i }).click();
  await expect(page.getByText('Staff frontend search')).toBeVisible();

  // Seed canonical jobs and recompute matches via the test-only routes.
  await request.post('/testing/seed-jobs');
  const profile = await page.evaluate(async () => {
    const response = await fetch('/api/testing-profile-id'); // Plan 2 doesn't ship this; test uses a heuristic below instead.
    return null;
  }).catch(() => null);

  // Get the profile ID from the edit link instead.
  await page.goto('/search-profiles');
  const editHref = await page.getByRole('link', { name: 'Staff frontend search' }).getAttribute('href');
  const profileId = editHref?.match(/(\d+)/)?.[1];
  expect(profileId).toBeTruthy();

  await request.post(`/testing/run-matching/${profileId}`);

  // Now view the feed.
  await page.goto(`/feed/${profileId}`);
  await expect(page.locator('article').first()).toBeVisible();
  await expect(page.getByText(/ai rationale pending/i).first()).toBeVisible();
});
```

(Create the fixture PDF — any small PDF works. On Windows: open a browser, print any short page to PDF, save as `tests/Fixtures/resumes/sample.pdf`. Size under 100 KB.)

- [ ] **Step 6: Run the E2E test**

```bash
docker compose up -d
/c/Users/david/.config/herd/bin/php84/php.exe artisan migrate:fresh --seed --env=testing
npm run test:e2e
```

Expected: the Playwright spec passes. If it fails at the "Ready to match" gate, the likely cause is the fake AiClient not being bound — verify `TestingServiceProvider` is loaded.

- [ ] **Step 7: Extend the hardcoded-hosts guard**

Edit `tests/Feature/Brand/NoHardcodedHostsTest.php` — add these patterns to the blocklist (or create the separate test file `tests/Feature/Brand/DriverHostsTest.php` if the existing test isn't easily extended):

```php
public function test_driver_hosts_not_leaked_outside_driver_classes(): void
{
    $hostPatterns = ['boards-api.greenhouse.io', 'api.lever.co', 'remoteok.com/api'];
    $allowedPathFragments = [
        'app/Services/Jobs/Drivers/',
        'config/services.php',
        'tests/', // fixtures and driver tests mention the hosts
    ];

    $offenders = [];
    foreach ($this->projectPhpFiles() as $file) {
        $content = file_get_contents($file);
        foreach ($hostPatterns as $pattern) {
            if (!str_contains($content, $pattern)) continue;
            $allowed = collect($allowedPathFragments)->contains(fn ($frag) => str_contains($file, $frag));
            if (!$allowed) {
                $offenders[] = "$file contains '$pattern'";
            }
        }
    }

    $this->assertSame([], $offenders);
}

protected function projectPhpFiles(): array
{
    $base = base_path();
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
    $out = [];
    foreach ($it as $f) {
        $path = $f->getPathname();
        if (!str_ends_with($path, '.php')) continue;
        if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) continue;
        if (str_contains($path, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR)) continue;
        $out[] = $path;
    }
    return $out;
}
```

- [ ] **Step 8: Rewrite `CLAUDE.md`**

Overwrite `CLAUDE.md` with the updated content reflecting Plan 2 realities. Keep the Plan 1 shape; replace or add sections below. Full suggested content:

```markdown
# Throughline — Claude Code instructions

A multi-user Laravel app that helps job seekers find roles that fit their career arc. See the design spec at [`docs/superpowers/specs/2026-04-21-throughline-design.md`](docs/superpowers/specs/2026-04-21-throughline-design.md) and the Plan 2 design at [`docs/superpowers/specs/2026-04-21-throughline-plan-2-design.md`](docs/superpowers/specs/2026-04-21-throughline-plan-2-design.md).

## Stack

- Laravel 12 on PHP 8.4
- Laravel Socialite (Google + GitHub) — no Fortify in this install
- Inertia v2 + Vue 3 + TypeScript + Tailwind v3
- PostgreSQL 16 + pgvector + Redis via Docker Compose (dev) / managed services (prod)
- Horizon for queues (prod); `queue:listen` for Windows dev (ext-pcntl is Linux-only)
- OpenAI `text-embedding-3-small` for embeddings, Anthropic Claude Sonnet 4.6 for parsing
- Poppler (`pdftotext`) + `phpoffice/phpword` for resume text extraction
- PHPUnit v11.5; Playwright for E2E; Pint for formatting

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
docker compose up -d
php artisan migrate --seed
composer run dev
```

Dev server: http://localhost:8000. Vite: 5173. Postgres: 5432 (Docker). Redis: 6379 (Docker).

## Dev environment quirks (Windows)

- Use explicit Herd PHP for artisan commands if the shim lacks `pdo_pgsql`: `/c/Users/david/.config/herd/bin/php84/php.exe artisan …`
- Horizon's `ext-pcntl` is Linux-only. Dev uses `queue:listen` (part of `composer run dev`); prod uses `horizon:work`.
- Poppler's `pdftotext.exe` must be on PATH for resume PDFs to parse locally. `winget install poppler` works.

## Key commands

- `docker compose up -d` — start Postgres + Redis containers (required before `composer run dev`).
- `docker compose logs -f postgres` — tail Postgres logs.
- `composer run dev` — start PHP + queue listener + Vite concurrently.
- `php artisan test --compact` — run the PHP suite.
- `npm run test:e2e` — run Playwright E2E smoke against the testing environment.
- `vendor/bin/pint --dirty` — format only changed files.
- `php artisan tinker` — REPL in app context.

## Architecture notes

- **`AiClient`** (`app/Services/Ai/AiClient.php`) is the single entry point for every OpenAI and Anthropic call. Never import the provider SDKs directly. `forUser($user)` sets attribution; `forUser(null)` marks the call as system-attributed (ingestion). Cost-ceiling enforcement, rate limiting, and `ai_usage_events` writes all live here.
- **`JobSourceDriver`** (`app/Contracts/Jobs/JobSourceDriver.php`) + `DriverRegistry` (`app/Services/Jobs/DriverRegistry.php`). Drivers know nothing about matching. `config/throughline.php` maps `source_type → driver class`.
- **Per-entity vectors** — resumes and canonical_jobs each carry their own `embedding vector(1536)`. No shared vector table. Eloquent can't cast pgvector natively, so reads and writes use raw `DB::statement`.
- **`BrandConfig`** (`app/Services/Brand/BrandConfig.php`) remains the source of truth for brand-facing strings. Never hardcode "Throughline" in templates.
- **`SiteSettings`** (`app/Services/SiteSettings.php`) — cached Redis singleton. Settings added in Plan 2: `stage1_score_floor`, `cost_ceiling_per_user_per_day_usd`, `openai_embeddings_model`, `anthropic_parse_model`.
- **First-user-to-admin** — unchanged from Plan 1.
- **No hardcoded host strings** — enforced by `tests/Feature/Brand/NoHardcodedHostsTest.php` and `DriverHostsTest.php`.

## What's here now (post Plan 2)

Resume upload with parse + embed, search-profile CRUD, three job-source drivers (Greenhouse, Lever, RemoteOK), stage-1 cosine matching fan-out, ranked feed with Plan-3 card shell (rationale slot shows a placeholder), admin CRUD for job sources with fetch-now. No stage-2 rerank, no application tracking, no analytics — those ship in Plan 3-6.
```

- [ ] **Step 9: Rewrite `README.md`**

Keep the existing Plan-1-era README shape but update the setup section to match the Docker-based dev loop (mirroring the CLAUDE.md snippet above). Add a "Running the E2E smoke" section.

- [ ] **Step 10: Run the full test suite**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --compact
```

Expected: every test passes — Plan 1 tests, all Plan 2 feature/unit tests, the driver-host guard. If anything fails, fix before committing.

- [ ] **Step 11: Manual smoke — end-to-end dogfood**

With real API keys in `.env` (`OPENAI_API_KEY`, `ANTHROPIC_API_KEY`):

1. `docker compose up -d`
2. `composer run dev`
3. Log in (first user becomes admin).
4. Navigate to `/admin/job-sources`; hit "Fetch now" on Stripe's Greenhouse board. Wait 30-60 seconds.
5. `docker exec throughline-postgres psql -U throughline -d throughline -c "SELECT count(*) FROM canonical_jobs;"` — expect a positive number.
6. Upload a real resume. Wait for "Ready to match".
7. Create a search profile linked to the resume with `remote_mode='any'`.
8. Open `/feed/{profile}`. Expect a ranked list with Plan-3 placeholder rationale cards.
9. Verify `ai_usage_events` has real rows:
   ```bash
   docker exec throughline-postgres psql -U throughline -d throughline -c "SELECT provider, model, operation, estimated_cost_usd FROM ai_usage_events ORDER BY created_at DESC LIMIT 10;"
   ```

- [ ] **Step 12: Final format + commit**

```bash
vendor/bin/pint --dirty
git add playwright.config.ts package.json package-lock.json tests/e2e tests/Fixtures/resumes routes/testing.php bootstrap/app.php app/Providers/TestingServiceProvider.php bootstrap/providers.php tests/Feature/Brand/NoHardcodedHostsTest.php tests/Feature/Brand/DriverHostsTest.php CLAUDE.md README.md
git commit -m "$(cat <<'EOF'
feat: Playwright E2E smoke + doc updates + driver-host guard

Playwright spec drives the Plan 2 happy path end-to-end: register,
upload resume, create search profile, see ranked feed with the Plan-3
placeholder rationale card. Runs against a testing environment where
AiClient is bound to a fake provider returning deterministic vectors,
and a routes/testing.php file exposes /testing/seed-jobs and
/testing/run-matching so the test doesn't depend on a real driver fetch.

CLAUDE.md and README rewritten to reflect the Docker-based dev loop,
new AiClient and JobSourceDriver architecture notes, and the list
of what Plan 2 ships vs what remains deferred.

DriverHostsTest extends the Plan 1 hardcoded-hosts guard to cover
the three driver upstream hostnames.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Done

Plan 2 is complete when all 15 tasks are green, `php artisan test --compact` and `npm run test:e2e` both pass, and the manual dogfood produces a ranked feed with rows populating `ai_usage_events`. Plan 3 starts by re-reading `docs/superpowers/specs/2026-04-21-throughline-design.md` §4.2 Flow C stage-2 details and picking up where `RationaleSlot.vue`'s placeholder currently renders.
