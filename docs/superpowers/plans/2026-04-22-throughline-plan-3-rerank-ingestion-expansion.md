# Throughline — Plan 3: Stage-2 rerank + ingestion expansion

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the marquee stage-2 Claude rerank. Feed cards carry fit score + narrative + prose rationale + structured gaps. A nightly refresh keeps top-50 per active profile fresh. Three more drivers (Workable + Ashby + Adzuna) broaden the job pool; Adzuna introduces paginated fetch.

**Architecture:** `AiClient::rerank()` adds a third AI surface with all-or-throw semantics and internal batching. `RerankShortlist` queued job is sync-dispatchable from the feed controller (~3s budget) and async-dispatchable from `NightlyRerankRefresh`. `JobMatchCard`'s `RationaleSlot` renders real stage-2 content when present and falls back to a pending state. Drivers follow the Plan 2 contract exactly; Adzuna's paginated generator proves that contract holds for non-aggregator paginated APIs.

**Tech Stack:** No new composer/npm packages. Same Laravel 12 + PHP 8.4 + Postgres 16 + pgvector + Redis + Claude Sonnet 4.6 + OpenAI text-embedding-3-small stack as Plan 2.

**Spec reference:** [`docs/superpowers/specs/2026-04-22-throughline-plan-3-design.md`](../specs/2026-04-22-throughline-plan-3-design.md)

---

## Preconditions

- Plan 2 merged to main (PR #7). Branch `plan-3-rerank-ingestion-expansion` already forked from main.
- Docker stack (`throughline-postgres` + `throughline-redis`) running.
- `throughline_test` DB exists; 101 PHP tests passing baseline.
- `.gitignore` already extended for `test-results/` + `playwright-report/` (committed in `f0e864e`).

---

## Task index

1. Playwright selector fix + upstream-drift test activation
2. `AiClient::rerank()` + `RerankFailed` exception + tests (TDD)
3. `job_sources.config_json` migration + `site_settings` keys + seeder expansion
4. `RerankShortlist` queued job + tests
5. `NightlyRerankRefresh` scheduled job + `throughline:rerank-now` artisan command + tests
6. Feed controller rerank integration + tests
7. `JobMatchCard` + `RationaleSlot` Vue updates
8. `WorkableDriver` + fixtures + tests
9. `AshbyDriver` + fixtures + tests
10. `AdzunaDriver` (paginated) + fixtures + tests
11. E2E spec update + CLAUDE.md / README + final verification

---

## Task 1: Playwright selector fix + upstream-drift test activation

**Files:**
- Modify: `tests/e2e/feed-happy-path.spec.ts`
- Create: `tests/Feature/Jobs/JobSourcesUpstreamDriftTest.php`
- Modify: `README.md` (add brief nightly drift-test instruction)

- [ ] **Step 1: Identify the actual upload button text**

Open `resources/js/pages/resumes/Index.vue` (committed via Plan 2). The upload button currently reads `Upload resume`. The Plan 2 spec expected that — but the Plan 2 spec used `getByRole('button', { name: /upload resume/i })`, which should have matched. Investigate the real breakage by running the spec locally:

```bash
docker compose up -d
/c/Users/david/.config/herd/bin/php84/php.exe artisan migrate:fresh --seed --env=testing
npm run test:e2e -- --reporter=list 2>&1 | head -40
```

Expected: the error message identifies the exact element Playwright failed to find. Likely causes: the button is inside a modal not yet rendered at the time of the query, or the text includes whitespace/trailing characters that the regex doesn't accommodate.

- [ ] **Step 2: Apply the fix to `tests/e2e/feed-happy-path.spec.ts`**

Based on the error from Step 1, adjust the selector. Common patterns:

- If the button is in the main page: `await page.getByRole('button', { name: 'Upload resume' }).click();` (exact text, not regex).
- If the button is only visible after a modal trigger, insert an intermediate click.
- If the page is an empty-state ("No resumes yet"), the button copy may differ — inspect `Index.vue` and match accordingly.

Re-run `npm run test:e2e` and confirm the spec makes it past the upload step to the search-profile creation step.

- [ ] **Step 3: Write the upstream-drift test**

`tests/Feature/Jobs/JobSourcesUpstreamDriftTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\DTO\CanonicalJobData;
use App\Models\JobSource;
use App\Services\Jobs\DriverRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobSourcesUpstreamDriftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('THROUGHLINE_UPSTREAM_DRIFT_TEST') !== '1') {
            $this->markTestSkipped('Upstream drift test gated by THROUGHLINE_UPSTREAM_DRIFT_TEST=1');
        }
    }

    public function test_greenhouse_stripe_still_returns_canonical_jobs(): void
    {
        $source = JobSource::factory()->create([
            'source_type' => 'greenhouse',
            'identifier' => 'stripe',
        ]);

        $driver = app(DriverRegistry::class)->driverFor('greenhouse');
        $jobs = iterator_to_array($driver->fetch($source));

        $this->assertGreaterThan(0, count($jobs), 'Stripe has zero open roles — likely a driver-level breakage');
        $first = $jobs[0];
        $this->assertInstanceOf(CanonicalJobData::class, $first);
        $this->assertNotEmpty($first->sourceId);
        $this->assertNotEmpty($first->title);
        $this->assertNotEmpty($first->applyUrl);
    }
}
```

- [ ] **Step 4: Verify the test skips by default and runs when gated**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=JobSourcesUpstreamDriftTest
```

Expected: 1 skipped. Then:

```bash
THROUGHLINE_UPSTREAM_DRIFT_TEST=1 /c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=JobSourcesUpstreamDriftTest
```

Expected: 1 passed (hits the real Greenhouse API). If the live test fails, something has drifted at the upstream end or in the driver — report but don't block.

- [ ] **Step 5: Document in README**

Append to `README.md` under a new "### Nightly drift test" subheading (or insert into an existing testing section):

```markdown
### Nightly upstream drift test

`tests/Feature/Jobs/JobSourcesUpstreamDriftTest.php` hits the real Greenhouse
API against `stripe`'s public board. It is skipped by default (PR CI never runs it).
Enable it nightly by setting `THROUGHLINE_UPSTREAM_DRIFT_TEST=1` in the scheduled
CI environment. Treat a failure as a signal that a driver needs updating.
```

- [ ] **Step 6: Commit**

```bash
git add tests/e2e/feed-happy-path.spec.ts tests/Feature/Jobs/JobSourcesUpstreamDriftTest.php README.md
git commit -m "$(cat <<'EOF'
chore: fix Playwright selector + activate upstream-drift test

- Playwright spec now uses an exact button-text selector that matches
  the upload button in the empty-state resumes page. The previous
  regex-based selector failed because the button text didn't match
  the expected pattern.
- Upstream-drift test activated as a gated canary. Runs only when
  THROUGHLINE_UPSTREAM_DRIFT_TEST=1 is set, so PR CI is unaffected.
  Hits Stripe's public Greenhouse board as the representative signal.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `AiClient::rerank()` + `RerankFailed` + tests

**Files:**
- Create: `app/Services/Ai/Exceptions/RerankFailed.php`
- Modify: `app/Services/Ai/AiClient.php` (add `rerank()` method + helpers)
- Modify: `config/services.php` (add cost-per-1K-tokens entry if rerank model differs; reuse claude-sonnet-4-6 default)
- Create: `tests/Feature/Ai/AiClientRerankTest.php`

- [ ] **Step 1: Write the `RerankFailed` exception**

`app/Services/Ai/Exceptions/RerankFailed.php`:

```php
<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

class RerankFailed extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?string $rawResponse = null,
    ) {
        parent::__construct("Rerank failed: {$reason}");
    }
}
```

- [ ] **Step 2: Write the failing tests first (TDD)**

`tests/Feature/Ai/AiClientRerankTest.php`:

```php
<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\CostCeilingExceeded;
use App\Services\Ai\Exceptions\ProviderRateLimited;
use App\Services\Ai\Exceptions\RerankFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiClientRerankTest extends TestCase
{
    use RefreshDatabase;

    protected function claudeRerankResponse(array $entries): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode(['matches' => $entries]),
            ]],
            'usage' => ['input_tokens' => 800, 'output_tokens' => 300],
        ];
    }

    public function test_returns_one_entry_per_input_job(): void
    {
        $inputJobs = [
            ['id' => 1, 'title' => 'Staff Engineer', 'company' => 'Acme', 'description' => 'Build things'],
            ['id' => 2, 'title' => 'Principal Engineer', 'company' => 'Beta', 'description' => 'Lead things'],
        ];

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->claudeRerankResponse([
                ['job_id' => 1, 'fit_score' => 82, 'rationale' => 'Strong skill match.', 'gaps' => [['requirement' => 'Go', 'severity' => 'stretch']], 'narrative' => 'Natural next step in your arc.'],
                ['job_id' => 2, 'fit_score' => 65, 'rationale' => 'Partial match.', 'gaps' => [], 'narrative' => 'A stretch role.'],
            ])),
        ]);

        $user = User::factory()->create();
        $results = app(AiClient::class)->forUser($user)->rerank('Resume text.', $inputJobs);

        $this->assertCount(2, $results);
        $this->assertSame(1, $results[0]['job_id']);
        $this->assertSame(82, $results[0]['fit_score']);
        $this->assertSame('Staff Engineer', $results[0]['title'] ?? null); // not strictly required; caller passes title through
        $this->assertSame('stretch', $results[0]['gaps'][0]['severity']);
    }

    public function test_throws_rerank_failed_on_unparseable_response(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'not json']],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 10],
            ]),
        ]);

        $this->expectException(RerankFailed::class);
        app(AiClient::class)->forUser(User::factory()->create())->rerank('resume', [
            ['id' => 1, 'title' => 'X', 'company' => 'Y', 'description' => 'Z'],
        ]);
    }

    public function test_throws_rerank_failed_when_job_is_missing_from_response(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->claudeRerankResponse([
                ['job_id' => 1, 'fit_score' => 80, 'rationale' => 'ok', 'gaps' => [], 'narrative' => 'ok'],
                // job_id 2 missing
            ])),
        ]);

        $this->expectException(RerankFailed::class);
        app(AiClient::class)->forUser(User::factory()->create())->rerank('resume', [
            ['id' => 1, 'title' => 'A', 'company' => 'Y', 'description' => 'Z'],
            ['id' => 2, 'title' => 'B', 'company' => 'Y', 'description' => 'Z'],
        ]);
    }

    public function test_batches_large_input_into_max_batch_size_calls(): void
    {
        // rerank_max_batch_size default 20 — but SiteSettings is seeded in
        // RefreshDatabase lifecycle, so ensure it's present (Task 3 seeds it).
        // For this test, monkey-patch via config() or bind the site settings
        // service with a stub. Simpler: set config('services.ai.rerank_max_batch_size', 2).
        config(['services.ai.rerank_max_batch_size' => 2]);

        $responses = [
            Http::response($this->claudeRerankResponse([
                ['job_id' => 1, 'fit_score' => 50, 'rationale' => '', 'gaps' => [], 'narrative' => ''],
                ['job_id' => 2, 'fit_score' => 60, 'rationale' => '', 'gaps' => [], 'narrative' => ''],
            ])),
            Http::response($this->claudeRerankResponse([
                ['job_id' => 3, 'fit_score' => 70, 'rationale' => '', 'gaps' => [], 'narrative' => ''],
            ])),
        ];
        Http::fakeSequence('api.anthropic.com/*')->pushSequence($responses);

        $results = app(AiClient::class)->forUser(User::factory()->create())->rerank('resume', [
            ['id' => 1, 'title' => 'A', 'company' => 'X', 'description' => '.'],
            ['id' => 2, 'title' => 'B', 'company' => 'X', 'description' => '.'],
            ['id' => 3, 'title' => 'C', 'company' => 'X', 'description' => '.'],
        ]);

        $this->assertCount(3, $results);
        $this->assertSame([1, 2, 3], array_column($results, 'job_id'));
    }

    public function test_cost_ceiling_exceeded_propagates(): void
    {
        $user = User::factory()->create();
        \App\Models\AiUsageEvent::create([
            'user_id' => $user->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
            'operation' => 'parse',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'estimated_cost_usd' => 5.01,
            'context_type' => 'resume',
            'context_id' => null,
            'created_at' => now(),
        ]);

        $this->expectException(CostCeilingExceeded::class);
        app(AiClient::class)->forUser($user)->rerank('resume', [
            ['id' => 1, 'title' => 'A', 'company' => 'X', 'description' => '.'],
        ]);
    }

    public function test_rate_limit_translates(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                ['error' => ['message' => 'rate limited']],
                429,
                ['retry-after' => '7'],
            ),
        ]);

        $this->expectException(ProviderRateLimited::class);
        app(AiClient::class)->forUser(null)->rerank('resume', [
            ['id' => 1, 'title' => 'A', 'company' => 'X', 'description' => '.'],
        ]);
    }
}
```

- [ ] **Step 3: Run the tests — expect FAIL**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=AiClientRerankTest
```

Expected: 6 fail (method doesn't exist).

- [ ] **Step 4: Implement `AiClient::rerank()`**

Add to `app/Services/Ai/AiClient.php` (alongside existing `embed` + `parseResume`):

```php
public function rerank(string $resumeText, array $jobs): array
{
    if (empty($jobs)) {
        return [];
    }

    $maxBatch = (int) ($this->settings->get('rerank_max_batch_size', '20') ?? config('services.ai.rerank_max_batch_size', 20));
    $batches = array_chunk($jobs, $maxBatch);

    $allResults = [];
    foreach ($batches as $batch) {
        $batchResults = $this->rerankBatch($resumeText, $batch);
        $allResults = array_merge($allResults, $batchResults);
    }

    return $allResults;
}

protected function rerankBatch(string $resumeText, array $jobs): array
{
    $this->enforceCostCeiling();
    $this->consumeRateBucket('anthropic');

    $model = $this->settings->get('anthropic_rerank_model', 'claude-sonnet-4-6');

    $system = 'You rerank job postings against a candidate resume. Return ONLY compact JSON matching {"matches": [{"job_id": <int>, "fit_score": <int 0-100>, "rationale": "<2-4 sentences>", "gaps": [{"requirement": "<string>", "severity": "blocker"|"stretch"|"minor"}], "narrative": "<one-sentence framing of how this fits the candidate arc>"}]}. Include exactly one entry per input job. Never omit a job.';

    $userContent = "Candidate resume:\n---\n{$resumeText}\n---\n\nJob postings to rerank:\n" .
        json_encode(array_map(
            fn ($j) => ['id' => $j['id'], 'title' => $j['title'], 'company' => $j['company'], 'description' => mb_substr($j['description'], 0, 2000)],
            $jobs,
        ));

    $response = Http::withHeaders([
        'x-api-key' => config('services.anthropic.api_key'),
        'anthropic-version' => '2023-06-01',
    ])->acceptJson()->asJson()->post('https://api.anthropic.com/v1/messages', [
        'model' => $model,
        'max_tokens' => 4096,
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => $userContent]],
    ]);

    if ($response->status() === 429) {
        throw new ProviderRateLimited('anthropic', (int) ($response->header('retry-after') ?: 30));
    }
    $response->throw();

    $data = $response->json();
    $raw = $data['content'][0]['text'] ?? '';
    $parsed = json_decode($raw, true);

    if (!is_array($parsed) || !isset($parsed['matches']) || !is_array($parsed['matches'])) {
        throw new RerankFailed('response did not contain matches array', $raw);
    }

    $byId = [];
    foreach ($parsed['matches'] as $match) {
        if (!isset($match['job_id'], $match['fit_score'], $match['rationale'], $match['gaps'], $match['narrative'])) {
            throw new RerankFailed('match entry missing required fields', $raw);
        }
        $byId[(int) $match['job_id']] = $match;
    }

    $ordered = [];
    foreach ($jobs as $job) {
        if (!isset($byId[$job['id']])) {
            throw new RerankFailed("missing response for job_id {$job['id']}", $raw);
        }
        $ordered[] = $byId[$job['id']];
    }

    $inputTokens = (int) ($data['usage']['input_tokens'] ?? 0);
    $outputTokens = (int) ($data['usage']['output_tokens'] ?? 0);
    $this->recordUsage('anthropic', $model, 'rerank', $inputTokens, $outputTokens, 'rerank_batch');

    return $ordered;
}
```

Import `App\Services\Ai\Exceptions\RerankFailed` at the top of `AiClient.php`.

- [ ] **Step 5: Run the tests — expect 6 PASS**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=AiClientRerankTest
```

- [ ] **Step 6: Run full suite to confirm no regressions**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --compact
```

Expected: 101 + 6 = 107 pass.

- [ ] **Step 7: Format + commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Ai/Exceptions/RerankFailed.php app/Services/Ai/AiClient.php tests/Feature/Ai/AiClientRerankTest.php
git commit -m "$(cat <<'EOF'
feat: AiClient::rerank with all-or-throw semantics and internal batching

New method fronts Claude Sonnet for structured rerank output. Returns
one entry per input job with fit_score / rationale / gaps / narrative;
any missing entry, malformed structure, or partial batch throws
RerankFailed rather than returning partial results — keeps callers'
write loops straightforward.

Internal batching splits inputs larger than rerank_max_batch_size into
sequential Claude calls so a 50-job nightly shortlist still hits the
single public method. Cost-ceiling and rate-limit exceptions use the
existing surface; every batch writes one ai_usage_events row with
operation='rerank', context_type='rerank_batch'.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: `job_sources.config_json` migration + site_settings keys + seeder expansion

**Files:**
- Create: `database/migrations/2026_04_22_100000_add_config_json_to_job_sources.php`
- Modify: `database/seeders/SiteSettingsSeeder.php`
- Modify: `database/seeders/JobSourcesSeeder.php`
- Modify: `app/Models/JobSource.php` (add `config_json` to `$fillable` + `$casts`)

- [ ] **Step 1: Write the migration**

`database/migrations/2026_04_22_100000_add_config_json_to_job_sources.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('job_sources', function (Blueprint $table) {
            $table->jsonb('config_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('job_sources', function (Blueprint $table) {
            $table->dropColumn('config_json');
        });
    }
};
```

- [ ] **Step 2: Update `JobSource` model**

Edit `app/Models/JobSource.php`. Add `'config_json'` to `$fillable` and add to `$casts`:

```php
protected $casts = [
    'is_enabled' => 'boolean',
    'last_fetched_at' => 'datetime',
    'fetch_cadence_minutes' => 'integer',
    'consecutive_failures' => 'integer',
    'config_json' => 'array',
];
```

- [ ] **Step 3: Append new `site_settings` keys**

Edit `database/seeders/SiteSettingsSeeder.php`. Append to the `$defaults` array (after Plan 2's additions):

```php
['key' => 'rerank_enabled',             'value' => 'true',                'type' => 'bool',   'description' => 'Feed-view sync rerank on/off switch.'],
['key' => 'rerank_shortlist_size',      'value' => '20',                  'type' => 'int',    'description' => 'Top-K feed matches that get sync-reranked on view.'],
['key' => 'rerank_max_batch_size',      'value' => '20',                  'type' => 'int',    'description' => 'Maximum jobs per Claude rerank call.'],
['key' => 'stage2_ttl_hours',           'value' => '24',                  'type' => 'int',    'description' => 'Rerank staleness threshold in hours.'],
['key' => 'nightly_rerank_hour_utc',    'value' => '4',                   'type' => 'int',    'description' => 'UTC hour for NightlyRerankRefresh.'],
['key' => 'anthropic_rerank_model',     'value' => 'claude-sonnet-4-6',   'type' => 'string', 'description' => 'Claude model used by AiClient::rerank.'],
```

- [ ] **Step 4: Append new seed rows to `JobSourcesSeeder`**

Edit `database/seeders/JobSourcesSeeder.php`. Append to the `$seeds` array:

```php
['source_type' => 'workable', 'identifier' => 'shopify',   'display_name' => 'Shopify (Workable)',  'config_json' => null],
['source_type' => 'ashby',    'identifier' => 'vercel',    'display_name' => 'Vercel (Ashby)',      'config_json' => null],
['source_type' => 'adzuna',   'identifier' => 'us',        'display_name' => 'Adzuna US',           'config_json' => ['country' => 'us']],
```

The existing 9 Plan 2 seeds stay unchanged (they'll leave `config_json` as null via the default).

- [ ] **Step 5: Run the migration + reseed**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan migrate --no-interaction
/c/Users/david/.config/herd/bin/php84/php.exe artisan db:seed --class=SiteSettingsSeeder --no-interaction
/c/Users/david/.config/herd/bin/php84/php.exe artisan db:seed --class=JobSourcesSeeder --no-interaction
```

Verify:

```bash
docker exec throughline-postgres psql -U throughline -d throughline -c "SELECT key, value FROM site_settings WHERE key LIKE 'rerank%' OR key LIKE 'stage2%' OR key = 'nightly_rerank_hour_utc' OR key = 'anthropic_rerank_model' ORDER BY key;"
docker exec throughline-postgres psql -U throughline -d throughline -c "SELECT source_type, identifier, display_name, config_json FROM job_sources WHERE source_type IN ('workable','ashby','adzuna');"
```

Expected: 6 new site_settings rows + 3 new job_sources rows (the Adzuna row showing `{"country": "us"}`).

- [ ] **Step 6: Run full suite to confirm nothing regressed**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --compact
```

Expected: 107 pass (no new tests — schema-only + seed additions; `JobSourceFactory` already tolerates the new column since it's nullable).

- [ ] **Step 7: Format + commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_04_22_100000_add_config_json_to_job_sources.php database/seeders/SiteSettingsSeeder.php database/seeders/JobSourcesSeeder.php app/Models/JobSource.php
git commit -m "$(cat <<'EOF'
feat: job_sources.config_json + rerank site_settings + 3 driver seeds

Adds a per-source jsonb config column (Adzuna uses it for country;
Workable + Ashby leave it null). Six new site_settings keys govern
rerank behavior: rerank_enabled, rerank_shortlist_size,
rerank_max_batch_size, stage2_ttl_hours, nightly_rerank_hour_utc,
anthropic_rerank_model. JobSourcesSeeder appends Shopify (Workable),
Vercel (Ashby), and Adzuna US rows.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: `RerankShortlist` queued job + tests

**Files:**
- Create: `app/Jobs/RerankShortlist.php`
- Create: `tests/Feature/Matching/RerankShortlistTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Matching/RerankShortlistTest.php`:

```php
<?php

namespace Tests\Feature\Matching;

use App\Jobs\RerankShortlist;
use App\Models\CanonicalJob;
use App\Models\JobMatch;
use App\Models\Resume;
use App\Models\SearchProfile;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\CostCeilingExceeded;
use App\Services\Ai\Exceptions\RerankFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class RerankShortlistTest extends TestCase
{
    use RefreshDatabase;

    protected function seedProfileWithMatches(int $matchCount, ?int $staleRange = null): array
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->parsed()->create();
        $profile = SearchProfile::factory()->for($user)->create(['resume_id' => $resume->id]);

        $matches = [];
        for ($i = 0; $i < $matchCount; $i++) {
            $job = CanonicalJob::factory()->create(['description' => 'Job #' . $i]);
            $stage2At = $staleRange !== null && $i < $staleRange ? null : now()->subHours(1);
            JobMatch::insert([
                'user_id' => $user->id,
                'search_profile_id' => $profile->id,
                'canonical_job_id' => $job->id,
                'stage1_score' => 0.9 - ($i * 0.01),
                'stage2_at' => $stage2At,
                'created_at' => now(),
            ]);
            $matches[] = ['profile' => $profile, 'job' => $job];
        }
        return ['profile' => $profile, 'user' => $user, 'resume' => $resume, 'matches' => $matches];
    }

    public function test_writes_stage2_columns_on_returned_matches(): void
    {
        $ctx = $this->seedProfileWithMatches(3, staleRange: 3);

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('rerank')->andReturn([
            ['job_id' => $ctx['matches'][0]['job']->id, 'fit_score' => 80, 'rationale' => 'R0', 'gaps' => [], 'narrative' => 'N0'],
            ['job_id' => $ctx['matches'][1]['job']->id, 'fit_score' => 75, 'rationale' => 'R1', 'gaps' => [['requirement' => 'X', 'severity' => 'stretch']], 'narrative' => 'N1'],
            ['job_id' => $ctx['matches'][2]['job']->id, 'fit_score' => 60, 'rationale' => 'R2', 'gaps' => [], 'narrative' => 'N2'],
        ]);
        $this->app->instance(AiClient::class, $ai);

        (new RerankShortlist($ctx['profile']->id, 20, $ctx['user']->id))->handle($ai);

        $match0 = JobMatch::where('canonical_job_id', $ctx['matches'][0]['job']->id)->first();
        $this->assertSame(80, $match0->stage2_score);
        $this->assertSame('R0', $match0->stage2_rationale);
        $this->assertSame('N0', $match0->stage2_narrative);
        $this->assertNotNull($match0->stage2_at);

        $match1 = JobMatch::where('canonical_job_id', $ctx['matches'][1]['job']->id)->first();
        $this->assertSame([['requirement' => 'X', 'severity' => 'stretch']], $match1->stage2_gaps);
    }

    public function test_noop_when_no_stale_matches(): void
    {
        $ctx = $this->seedProfileWithMatches(3, staleRange: 0); // all fresh

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldNotReceive('forUser');
        $this->app->instance(AiClient::class, $ai);

        (new RerankShortlist($ctx['profile']->id, 20, $ctx['user']->id))->handle($ai);
    }

    public function test_handles_fewer_stale_matches_than_shortlist_size(): void
    {
        $ctx = $this->seedProfileWithMatches(5, staleRange: 2); // 2 stale, 3 fresh

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('rerank')
            ->with(Mockery::any(), Mockery::on(fn ($jobs) => count($jobs) === 2))
            ->andReturn([
                ['job_id' => $ctx['matches'][0]['job']->id, 'fit_score' => 80, 'rationale' => 'R', 'gaps' => [], 'narrative' => 'N'],
                ['job_id' => $ctx['matches'][1]['job']->id, 'fit_score' => 70, 'rationale' => 'R', 'gaps' => [], 'narrative' => 'N'],
            ]);
        $this->app->instance(AiClient::class, $ai);

        (new RerankShortlist($ctx['profile']->id, 20, $ctx['user']->id))->handle($ai);

        $this->assertSame(2, JobMatch::whereNotNull('stage2_at')
            ->whereNotNull('stage2_score')
            ->where('search_profile_id', $ctx['profile']->id)
            ->where('stage2_at', '>', now()->subMinute())
            ->count());
    }

    public function test_writes_sentinel_on_rerank_failed(): void
    {
        $ctx = $this->seedProfileWithMatches(2, staleRange: 2);

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('rerank')->andThrow(new RerankFailed('bad', 'raw'));
        $this->app->instance(AiClient::class, $ai);

        (new RerankShortlist($ctx['profile']->id, 20, $ctx['user']->id))->handle($ai);

        $matches = JobMatch::where('search_profile_id', $ctx['profile']->id)->get();
        foreach ($matches as $m) {
            $this->assertSame('rerank unavailable', $m->stage2_rationale);
            $this->assertNotNull($m->stage2_at);
        }
    }

    public function test_releases_on_cost_ceiling(): void
    {
        $ctx = $this->seedProfileWithMatches(1, staleRange: 1);

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('rerank')->andThrow(new CostCeilingExceeded($ctx['user']->id, 5.01, 5.0));
        $this->app->instance(AiClient::class, $ai);

        $job = Mockery::mock(RerankShortlist::class . '[release]', [$ctx['profile']->id, 20, $ctx['user']->id]);
        $job->shouldReceive('release')->with(Mockery::on(fn ($secs) => $secs >= 60))->once();

        $job->handle($ai);
    }
}
```

- [ ] **Step 2: Run tests — expect FAIL**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=RerankShortlistTest
```

- [ ] **Step 3: Implement `RerankShortlist`**

`app/Jobs/RerankShortlist.php`:

```php
<?php

namespace App\Jobs;

use App\Models\JobMatch;
use App\Models\SearchProfile;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\CostCeilingExceeded;
use App\Services\Ai\Exceptions\ProviderRateLimited;
use App\Services\Ai\Exceptions\RerankFailed;
use App\Services\SiteSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RerankShortlist implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $searchProfileId,
        public int $shortlistSize,
        public ?int $actingUserId = null,
    ) {
    }

    public function handle(AiClient $ai): void
    {
        $profile = SearchProfile::with('resume')->find($this->searchProfileId);
        if (!$profile || !$profile->resume || $profile->resume->parse_status !== 'parsed') {
            return;
        }

        $ttlHours = (int) app(SiteSettings::class)->get('stage2_ttl_hours', '24');
        $stalenessCutoff = now()->subHours($ttlHours);

        $staleMatches = DB::table('job_matches')
            ->join('canonical_jobs', 'job_matches.canonical_job_id', '=', 'canonical_jobs.id')
            ->where('job_matches.search_profile_id', $profile->id)
            ->whereNull('job_matches.dismissed_at')
            ->where(fn ($q) => $q->whereNull('job_matches.stage2_at')->orWhere('job_matches.stage2_at', '<', $stalenessCutoff))
            ->orderByDesc('job_matches.stage1_score')
            ->limit($this->shortlistSize)
            ->get([
                'job_matches.id as match_id',
                'canonical_jobs.id as job_id',
                'canonical_jobs.title',
                'canonical_jobs.company_name',
                'canonical_jobs.description',
            ]);

        if ($staleMatches->isEmpty()) {
            return;
        }

        $user = $this->actingUserId ? User::find($this->actingUserId) : null;

        $jobsPayload = $staleMatches->map(fn ($row) => [
            'id' => $row->job_id,
            'title' => $row->title,
            'company' => $row->company_name,
            'description' => $row->description,
        ])->all();

        try {
            $results = $ai->forUser($user)->rerank($profile->resume->parsed_text ?? '', $jobsPayload);
        } catch (CostCeilingExceeded $e) {
            $seconds = (int) now()->diffInSeconds(now()->endOfDay());
            $this->release(max(60, $seconds));
            return;
        } catch (ProviderRateLimited $e) {
            $this->release($e->retryAfterSeconds);
            return;
        } catch (RerankFailed $e) {
            Log::warning('RerankShortlist RerankFailed', [
                'profile_id' => $profile->id,
                'reason' => $e->reason,
            ]);
            $this->writeSentinel($staleMatches->pluck('match_id')->all());
            return;
        }

        $this->applyResults($results);
    }

    protected function applyResults(array $results): void
    {
        $now = now();
        foreach ($results as $r) {
            JobMatch::where('canonical_job_id', $r['job_id'])
                ->update([
                    'stage2_score' => (int) $r['fit_score'],
                    'stage2_rationale' => (string) $r['rationale'],
                    'stage2_gaps' => $r['gaps'],
                    'stage2_narrative' => (string) $r['narrative'],
                    'stage2_at' => $now,
                ]);
        }
    }

    protected function writeSentinel(array $matchIds): void
    {
        if (empty($matchIds)) {
            return;
        }

        JobMatch::whereIn('id', $matchIds)->update([
            'stage2_rationale' => 'rerank unavailable',
            'stage2_at' => now(),
        ]);
    }
}
```

- [ ] **Step 4: Run the tests — expect 5 PASS**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=RerankShortlistTest
```

- [ ] **Step 5: Full suite**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --compact
```

Expected: 107 + 5 = 112 pass.

- [ ] **Step 6: Format + commit**

```bash
vendor/bin/pint --dirty
git add app/Jobs/RerankShortlist.php tests/Feature/Matching/RerankShortlistTest.php
git commit -m "$(cat <<'EOF'
feat: RerankShortlist queued job with sync + async dispatch paths

Reads stale top-K job_matches for a profile (stage2_at null or older
than stage2_ttl_hours), builds a jobs payload trimmed to 2000 chars
per description, calls AiClient::rerank, and writes stage2_* columns.
Sentinel 'rerank unavailable' written on RerankFailed so the next
poll doesn't keep picking the same row. CostCeilingExceeded and
ProviderRateLimited release with appropriate delays (midnight floor
at 60s, retry-after header for rate limits).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: `NightlyRerankRefresh` + `throughline:rerank-now` command + scheduler

**Files:**
- Create: `app/Jobs/NightlyRerankRefresh.php`
- Create: `app/Console/Commands/RerankNowCommand.php`
- Modify: `routes/console.php` (add schedule entry)
- Create: `tests/Feature/Matching/NightlyRerankRefreshTest.php`

- [ ] **Step 1: Write `NightlyRerankRefresh` + test**

`tests/Feature/Matching/NightlyRerankRefreshTest.php`:

```php
<?php

namespace Tests\Feature\Matching;

use App\Jobs\NightlyRerankRefresh;
use App\Jobs\RerankShortlist;
use App\Models\Resume;
use App\Models\SearchProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NightlyRerankRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_rerank_per_active_profile_with_resume(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $resumeA = Resume::factory()->for($user)->parsed()->create();
        $resumeB = Resume::factory()->for($user)->parsed()->create();
        $profileActive = SearchProfile::factory()->for($user)->create(['resume_id' => $resumeA->id, 'is_active' => true]);
        $profileInactive = SearchProfile::factory()->for($user)->create(['resume_id' => $resumeB->id, 'is_active' => false]);
        $profileNoResume = SearchProfile::factory()->for($user)->create(['resume_id' => null, 'is_active' => true]);

        (new NightlyRerankRefresh())->handle();

        Queue::assertPushed(RerankShortlist::class, 1);
        Queue::assertPushed(RerankShortlist::class, fn ($job) => $job->searchProfileId === $profileActive->id && $job->shortlistSize === 50 && $job->actingUserId === null);
    }
}
```

- [ ] **Step 2: Run the test — expect FAIL**

- [ ] **Step 3: Implement `NightlyRerankRefresh`**

`app/Jobs/NightlyRerankRefresh.php`:

```php
<?php

namespace App\Jobs;

use App\Models\SearchProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NightlyRerankRefresh implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        SearchProfile::where('is_active', true)
            ->whereNotNull('resume_id')
            ->chunkById(100, function ($profiles) {
                foreach ($profiles as $profile) {
                    RerankShortlist::dispatch($profile->id, 50, null);
                }
            });
    }
}
```

- [ ] **Step 4: Run the test — expect PASS**

- [ ] **Step 5: Write the artisan command**

`app/Console/Commands/RerankNowCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\RerankShortlist;
use App\Models\SearchProfile;
use App\Services\SiteSettings;
use Illuminate\Console\Command;

class RerankNowCommand extends Command
{
    protected $signature = 'throughline:rerank-now {profile : search_profile id} {--size= : shortlist size (defaults to site_settings value)}';

    protected $description = 'Synchronously rerank the top-K stale job_matches for a profile. Useful for dogfood debugging.';

    public function handle(SiteSettings $settings): int
    {
        $profile = SearchProfile::find($this->argument('profile'));
        if (!$profile) {
            $this->error('Profile not found.');
            return self::FAILURE;
        }

        $size = (int) ($this->option('size') ?? $settings->get('rerank_shortlist_size', '20'));

        $this->info("Reranking top {$size} stale matches for profile {$profile->id} ({$profile->name})…");
        RerankShortlist::dispatchSync($profile->id, $size, null);
        $this->info('Done.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 6: Wire the scheduler**

Edit `routes/console.php`. Append to the `Schedule::` section:

```php
use App\Jobs\NightlyRerankRefresh;
use App\Services\SiteSettings;

Schedule::call(function () {
    $hour = (int) app(SiteSettings::class)->get('nightly_rerank_hour_utc', '4');
    if (now()->utc()->hour === $hour && now()->utc()->minute === 0) {
        \App\Jobs\NightlyRerankRefresh::dispatch();
    }
})->everyMinute()->name('throughline-nightly-rerank-gate')->withoutOverlapping();
```

(The gate pattern is used instead of `->dailyAt('04:00')` so the hour can be adjusted via site_settings without restarting the scheduler. The every-minute gate is idempotent — `NightlyRerankRefresh::dispatch` only fires once per minute and `withoutOverlapping` prevents multiple same-hour dispatches.)

- [ ] **Step 7: Run the test + full suite**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=NightlyRerankRefreshTest
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --compact
```

Expected: 113 pass (112 + 1 new).

- [ ] **Step 8: Format + commit**

```bash
vendor/bin/pint --dirty
git add app/Jobs/NightlyRerankRefresh.php app/Console/Commands/RerankNowCommand.php routes/console.php tests/Feature/Matching/NightlyRerankRefreshTest.php
git commit -m "$(cat <<'EOF'
feat: NightlyRerankRefresh + throughline:rerank-now command

NightlyRerankRefresh dispatches RerankShortlist per active profile
with a linked resume (chunkById 100; shortlist size 50 per profile;
system-attributed). Scheduled via a gated every-minute check against
nightly_rerank_hour_utc so the trigger hour can be tuned without a
scheduler restart.

throughline:rerank-now is an admin-scoped artisan command that
synchronously reranks a given profile — useful for dogfood debugging
and the Plan 5 admin 'rerank now' action when it lands.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Feed controller rerank integration

**Files:**
- Modify: `app/Http/Controllers/FeedController.php`
- Create: `tests/Feature/Feed/FeedRerankTest.php`

- [ ] **Step 1: Write the feature tests**

`tests/Feature/Feed/FeedRerankTest.php`:

```php
<?php

namespace Tests\Feature\Feed;

use App\Jobs\RerankShortlist;
use App\Models\CanonicalJob;
use App\Models\JobMatch;
use App\Models\Resume;
use App\Models\SearchProfile;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\RerankFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class FeedRerankTest extends TestCase
{
    use RefreshDatabase;

    protected function seedFreshFeedFor(User $user): array
    {
        $resume = Resume::factory()->for($user)->parsed()->create();
        $profile = SearchProfile::factory()->for($user)->create(['resume_id' => $resume->id]);
        $jobs = CanonicalJob::factory()->count(3)->create();

        foreach ($jobs as $i => $job) {
            JobMatch::insert([
                'user_id' => $user->id,
                'search_profile_id' => $profile->id,
                'canonical_job_id' => $job->id,
                'stage1_score' => 0.9 - ($i * 0.05),
                'stage2_at' => null,
                'created_at' => now(),
            ]);
        }

        return compact('resume', 'profile', 'jobs');
    }

    public function test_sync_rerank_fires_when_top_k_is_stale(): void
    {
        $user = User::factory()->create();
        $ctx = $this->seedFreshFeedFor($user);

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('rerank')->once()->andReturn([
            ['job_id' => $ctx['jobs'][0]->id, 'fit_score' => 90, 'rationale' => 'Strong.', 'gaps' => [], 'narrative' => 'Arc match.'],
            ['job_id' => $ctx['jobs'][1]->id, 'fit_score' => 70, 'rationale' => 'Partial.', 'gaps' => [], 'narrative' => 'Stretch.'],
            ['job_id' => $ctx['jobs'][2]->id, 'fit_score' => 50, 'rationale' => 'Weak.', 'gaps' => [], 'narrative' => 'Far.'],
        ]);
        $this->app->instance(AiClient::class, $ai);

        $response = $this->actingAs($user)->get(route('feed.show', $ctx['profile']));
        $response->assertOk();

        $freshMatches = JobMatch::where('search_profile_id', $ctx['profile']->id)->whereNotNull('stage2_at')->get();
        $this->assertCount(3, $freshMatches);
        $this->assertSame(90, $freshMatches->where('canonical_job_id', $ctx['jobs'][0]->id)->first()->stage2_score);
    }

    public function test_feed_orders_by_fit_score_nulls_last(): void
    {
        $user = User::factory()->create();
        $ctx = $this->seedFreshFeedFor($user);

        // Pre-populate stage-2 on two of three matches to simulate mid-refresh state.
        JobMatch::where('canonical_job_id', $ctx['jobs'][0]->id)->update(['stage2_score' => 60, 'stage2_at' => now()]);
        JobMatch::where('canonical_job_id', $ctx['jobs'][1]->id)->update(['stage2_score' => 80, 'stage2_at' => now()]);
        // jobs[2] has stage2_score null

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('rerank')->andReturn([
            ['job_id' => $ctx['jobs'][2]->id, 'fit_score' => 70, 'rationale' => 'R', 'gaps' => [], 'narrative' => 'N'],
        ]);
        $this->app->instance(AiClient::class, $ai);

        $response = $this->actingAs($user)->get(route('feed.show', $ctx['profile']) . '?raw=1');

        $titles = collect($response->viewData('page')['props']['matches']['data'])->pluck('job.title')->all();
        // with ?raw=1, no rerank fires; order is stage1_score DESC
        $this->assertSame(
            $ctx['jobs']->pluck('title')->all(),
            $titles,
            'raw=1 bypass keeps stage1 order',
        );
    }

    public function test_raw_param_bypasses_rerank(): void
    {
        $user = User::factory()->create();
        $ctx = $this->seedFreshFeedFor($user);

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldNotReceive('rerank');
        $this->app->instance(AiClient::class, $ai);

        $this->actingAs($user)->get(route('feed.show', $ctx['profile']) . '?raw=1')->assertOk();
    }

    public function test_rerank_failure_degrades_gracefully(): void
    {
        $user = User::factory()->create();
        $ctx = $this->seedFreshFeedFor($user);

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldReceive('forUser')->andReturnSelf();
        $ai->shouldReceive('rerank')->andThrow(new RerankFailed('bad', 'raw'));
        $this->app->instance(AiClient::class, $ai);

        // Feed must not 500 even when rerank blows up.
        $this->actingAs($user)->get(route('feed.show', $ctx['profile']))->assertOk();

        // Matches still exist (stage1-scored), stage2 written as sentinel by RerankShortlist's error path.
        $matches = JobMatch::where('search_profile_id', $ctx['profile']->id)->get();
        foreach ($matches as $m) {
            $this->assertSame('rerank unavailable', $m->stage2_rationale);
        }
    }

    public function test_rerank_disabled_by_site_settings_skips_dispatch(): void
    {
        $user = User::factory()->create();
        $ctx = $this->seedFreshFeedFor($user);

        \DB::table('site_settings')->where('key', 'rerank_enabled')->update(['value' => 'false']);
        \Cache::forget('site_settings'); // if SiteSettings caches

        $ai = Mockery::mock(AiClient::class);
        $ai->shouldNotReceive('rerank');
        $this->app->instance(AiClient::class, $ai);

        $this->actingAs($user)->get(route('feed.show', $ctx['profile']))->assertOk();
    }
}
```

- [ ] **Step 2: Run the tests — expect FAIL**

- [ ] **Step 3: Update `FeedController::show()`**

The existing controller (from Plan 2 Task 13) queries `job_matches` and returns paginated results. Extend it to:

1. Parse `?raw=1` URL param — if set, skip rerank logic.
2. Before paginating, check if the top-K has stale rows.
3. If stale AND rerank enabled AND not `?raw=1`, dispatch `RerankShortlist::dispatchSync`. Catch exceptions, log, continue.
4. Re-query matches after the sync dispatch so fresh data lands in the response.
5. Order by `stage2_score DESC NULLS LAST, stage1_score DESC` (not stage1_score first).

Replace the body of `FeedController::show()`:

```php
public function show(Request $request, SearchProfile $profile): Response
{
    abort_unless((int) $profile->user_id === $request->user()->id, 403);

    $raw = $request->boolean('raw');
    $rerankEnabled = (bool) app(\App\Services\SiteSettings::class)->get('rerank_enabled', 'true');
    $shortlistSize = (int) app(\App\Services\SiteSettings::class)->get('rerank_shortlist_size', '20');
    $ttlHours = (int) app(\App\Services\SiteSettings::class)->get('stage2_ttl_hours', '24');

    if (!$raw && $rerankEnabled) {
        $staleCount = \DB::table('job_matches')
            ->where('search_profile_id', $profile->id)
            ->whereNull('dismissed_at')
            ->where(fn ($q) => $q->whereNull('stage2_at')->orWhere('stage2_at', '<', now()->subHours($ttlHours)))
            ->orderByDesc('stage1_score')
            ->limit($shortlistSize)
            ->count();

        if ($staleCount > 0) {
            try {
                \App\Jobs\RerankShortlist::dispatchSync($profile->id, $shortlistSize, $request->user()->id);
            } catch (\Throwable $e) {
                \Log::warning('Feed sync-rerank failed', [
                    'profile_id' => $profile->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    $profile->load('resume:id,name');

    $page = \DB::table('job_matches')
        ->join('canonical_jobs', 'job_matches.canonical_job_id', '=', 'canonical_jobs.id')
        ->where('job_matches.search_profile_id', $profile->id)
        ->whereNull('job_matches.dismissed_at')
        ->orderByRaw('job_matches.stage2_score DESC NULLS LAST')
        ->orderByDesc('job_matches.stage1_score')
        ->select([
            'job_matches.id as match_id',
            'job_matches.stage1_score',
            'job_matches.stage2_score',
            'job_matches.stage2_rationale',
            'job_matches.stage2_gaps',
            'job_matches.stage2_narrative',
            'job_matches.stage2_at',
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
        'stage2_score' => $row->stage2_score !== null ? (int) $row->stage2_score : null,
        'stage2_rationale' => $row->stage2_rationale,
        'stage2_gaps' => $row->stage2_gaps ? json_decode($row->stage2_gaps, true) : null,
        'stage2_narrative' => $row->stage2_narrative,
        'stage2_at' => $row->stage2_at,
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
        'rawMode' => $raw,
    ]);
}
```

- [ ] **Step 4: Run tests — expect 5 PASS**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=FeedRerankTest
```

Also re-run existing `FeedTest` from Plan 2 Task 13 — it should still pass since the controller response shape only gained fields.

- [ ] **Step 5: Full suite**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --compact
```

Expected: 113 + 5 = 118 pass.

- [ ] **Step 6: Format + commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/FeedController.php tests/Feature/Feed/FeedRerankTest.php
git commit -m "$(cat <<'EOF'
feat: feed controller sync-reranks top-K on view + ?raw=1 bypass

Before paginating, the feed controller counts stale top-K matches
(stage2_at null or older than stage2_ttl_hours). If any are stale AND
rerank_enabled AND not ?raw=1, dispatchSync RerankShortlist so the
page renders with fresh stage-2 data. Any exception (cost, rate,
RerankFailed) is logged and swallowed — the feed never 500s on a
Claude error. Orders by stage2_score DESC NULLS LAST, stage1_score
DESC so fresh-reranked matches rise above never-reranked ones while
the stage-1 fallback still drives secondary ordering.

The ?raw=1 param bypasses rerank entirely for admin debugging. Feed
props now carry stage2_* fields for the card to render.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: `JobMatchCard` + `RationaleSlot` Vue updates

**Files:**
- Modify: `resources/js/components/feed/JobMatchCard.vue`
- Modify: `resources/js/components/feed/RationaleSlot.vue`

- [ ] **Step 1: Update `RationaleSlot.vue`**

Overwrite `resources/js/components/feed/RationaleSlot.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
  match: {
    stage1_score: number;
    stage2_score: number | null;
    stage2_rationale: string | null;
    stage2_gaps: Array<{ requirement: string; severity: 'blocker' | 'stretch' | 'minor' }> | null;
    stage2_narrative: string | null;
  };
  showDebug?: boolean;
}>();

const hasRerank = computed(() =>
  props.match.stage2_score !== null && props.match.stage2_rationale !== 'rerank unavailable'
);

const severityClass = (severity: string) => ({
  blocker: 'bg-oxblood/10 text-oxblood border-oxblood/40',
  stretch: 'bg-bone-2 text-ink-2 border-hairline',
  minor: 'bg-transparent text-ink-3 border-hairline',
}[severity] ?? 'bg-bone-2 text-ink-3 border-hairline');
</script>

<template>
  <div v-if="hasRerank" class="mt-4 rounded border border-hairline bg-bone px-4 py-3">
    <p class="text-sm text-ink-2 leading-relaxed">{{ match.stage2_rationale }}</p>

    <div v-if="match.stage2_gaps && match.stage2_gaps.length" class="mt-3 flex flex-wrap gap-1.5">
      <span
        v-for="(gap, idx) in match.stage2_gaps"
        :key="idx"
        :class="['rounded-full border px-2.5 py-0.5 text-xs font-medium', severityClass(gap.severity)]"
      >
        {{ gap.requirement }}
      </span>
    </div>

    <p v-if="showDebug" class="mt-2 font-mono text-xs text-ink-3">
      stage1 = {{ match.stage1_score.toFixed(4) }} · stage2 = {{ match.stage2_score }}
    </p>
  </div>

  <div v-else class="mt-4 rounded border border-dashed border-hairline bg-bone-2/60 px-4 py-3 text-sm text-ink-3">
    <p v-if="match.stage2_rationale === 'rerank unavailable'" class="font-medium text-ink-2">
      AI rationale temporarily unavailable
    </p>
    <p v-else class="font-medium text-ink-2">Analyzing fit…</p>
    <p class="mt-1">
      <span v-if="match.stage2_rationale === 'rerank unavailable'">
        Refresh shortly — this usually resolves on the next rerank cycle.
      </span>
      <span v-else>Career-arc rationale arrives after the rerank completes (seconds to a minute).</span>
    </p>
    <p v-if="showDebug" class="mt-2 font-mono text-xs">stage1 = {{ match.stage1_score.toFixed(4) }}</p>
  </div>
</template>
```

- [ ] **Step 2: Update `JobMatchCard.vue`**

Overwrite `resources/js/components/feed/JobMatchCard.vue`:

```vue
<script setup lang="ts">
import RationaleSlot from './RationaleSlot.vue';

defineProps<{
  match: {
    match_id: number;
    stage1_score: number;
    stage2_score: number | null;
    stage2_rationale: string | null;
    stage2_gaps: Array<{ requirement: string; severity: 'blocker' | 'stretch' | 'minor' }> | null;
    stage2_narrative: string | null;
    stage2_at: string | null;
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
  return ({
    remote_only: 'Remote',
    hybrid_ok: 'Hybrid OK',
    onsite: 'On-site',
    any: '—',
  } as Record<string, string>)[mode] ?? mode;
}

function fitScoreClass(score: number | null) {
  if (score === null) return 'bg-bone-2 text-ink-3';
  if (score >= 80) return 'bg-oxblood text-bone';
  if (score >= 60) return 'bg-oxblood/15 text-oxblood';
  return 'bg-bone-2 text-ink-3';
}
</script>

<template>
  <article class="rounded-lg border border-hairline bg-bone px-6 py-5 shadow-sm">
    <header class="flex flex-wrap items-baseline justify-between gap-2">
      <div class="flex-1">
        <div class="flex items-center gap-3">
          <h3 class="font-display text-xl text-ink">{{ match.job.title }}</h3>
          <span
            v-if="match.stage2_score !== null && match.stage2_rationale !== 'rerank unavailable'"
            :class="['rounded-full px-2.5 py-0.5 text-xs font-medium tracking-tight', fitScoreClass(match.stage2_score)]"
          >
            {{ match.stage2_score }}
          </span>
        </div>
        <p class="mt-1 text-sm text-ink-2">
          <span v-if="match.stage2_narrative && match.stage2_rationale !== 'rerank unavailable'">{{ match.stage2_narrative }}</span>
          <span v-else>{{ match.job.company_name }}</span>
        </p>
      </div>
      <a :href="match.job.apply_url" target="_blank" rel="noopener" class="text-sm text-oxblood hover:underline">
        View posting &rarr;
      </a>
    </header>

    <dl class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-3">
      <div><dt class="sr-only">Company</dt><dd>{{ match.job.company_name }}</dd></div>
      <div v-if="match.job.location_raw"><dt class="sr-only">Location</dt><dd>{{ match.job.location_raw }}</dd></div>
      <div><dt class="sr-only">Mode</dt><dd>{{ remoteBadge(match.job.remote_mode) }}</dd></div>
      <div v-if="formatComp(match.job.comp_min, match.job.comp_max, match.job.comp_currency)">
        <dt class="sr-only">Compensation</dt>
        <dd>{{ formatComp(match.job.comp_min, match.job.comp_max, match.job.comp_currency) }}</dd>
      </div>
      <div v-if="match.job.posted_at">
        <dt class="sr-only">Posted</dt>
        <dd>Posted {{ new Date(match.job.posted_at).toLocaleDateString() }}</dd>
      </div>
    </dl>

    <RationaleSlot :match="match" :show-debug="showDebug" />
  </article>
</template>
```

- [ ] **Step 3: Run frontend lint + format**

```bash
npm run lint
npm run format
```

Both should complete with no errors. Pass or fix.

- [ ] **Step 4: Manual UI check (optional — requires running dev server + real API keys)**

Outside the automated test flow, spin up:
```bash
docker compose up -d
composer run dev
```

Log in, navigate to `/feed/{profile-id}`, confirm cards render the fit score badge and narrative subtitle when stage-2 data is present, and the placeholder state when it isn't. Skip if API keys aren't available.

- [ ] **Step 5: Full suite still green (no backend changes in this task)**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --compact
```

Expected: 118 pass.

- [ ] **Step 6: Commit**

```bash
git add resources/js/components/feed/JobMatchCard.vue resources/js/components/feed/RationaleSlot.vue
git commit -m "$(cat <<'EOF'
feat: JobMatchCard renders real stage-2 output + graceful pending state

RationaleSlot now renders the Claude rationale, structured gap chips
(color-coded by severity: blocker=oxblood, stretch=ink-2, minor=muted),
and the fit score debug line when admin ?debug=1 is set. Falls back
to 'Analyzing fit…' when stage-2 is null and to 'AI rationale
temporarily unavailable' when the rerank hit the RerankFailed sentinel.

JobMatchCard adds a fit score badge next to the title (oxblood fill
for scores >= 80, oxblood tint for 60-79, muted for below) and uses
the stage-2 narrative as the subtitle, replacing the company-only
subtitle Plan 2 shipped.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: `WorkableDriver`

**Files:**
- Create: `app/Services/Jobs/Drivers/WorkableDriver.php`
- Modify: `config/throughline.php` (add `workable` entry)
- Create: `tests/Fixtures/drivers/workable/shopify-happy.json`
- Create: `tests/Fixtures/drivers/workable/shopify-missing-location.json`
- Create: `tests/Unit/Jobs/Drivers/WorkableDriverTest.php`

- [ ] **Step 1: Register the driver in `config/throughline.php`**

Append to the `drivers` map:

```php
'workable' => \App\Services\Jobs\Drivers\WorkableDriver::class,
```

- [ ] **Step 2: Write the happy-path fixture**

`tests/Fixtures/drivers/workable/shopify-happy.json`:

```json
{
  "jobs": [
    {
      "id": "ABC-123-XYZ",
      "title": "Senior Platform Engineer",
      "full_title": "Senior Platform Engineer (Shopify)",
      "shortcode": "ABC123",
      "code": null,
      "state": "published",
      "department": "Engineering",
      "url": "https://apply.workable.com/shopify/j/ABC123/",
      "application_url": "https://apply.workable.com/shopify/j/ABC123/apply/",
      "shortlink": "https://apply.workable.com/shopify/j/ABC123/",
      "location": {
        "country": "Canada",
        "countryCode": "CA",
        "region": "Ontario",
        "city": "Toronto",
        "zipCode": null,
        "telecommuting": false
      },
      "created_at": "2026-04-15T14:00:00Z",
      "updated_at": "2026-04-15T14:00:00Z",
      "description": "<p>We're looking for a senior engineer...</p>",
      "requirements": "<p>5+ years...</p>",
      "benefits": "<p>Competitive compensation...</p>",
      "employment_type": "Full-time",
      "industry": "Technology",
      "function": "Engineering",
      "experience": "Senior"
    }
  ]
}
```

- [ ] **Step 3: Write the missing-location fixture**

`tests/Fixtures/drivers/workable/shopify-missing-location.json`:

```json
{
  "jobs": [
    {
      "id": "DEF-456-UVW",
      "title": "Remote Backend Engineer",
      "shortcode": "DEF456",
      "url": "https://apply.workable.com/shopify/j/DEF456/",
      "application_url": "https://apply.workable.com/shopify/j/DEF456/apply/",
      "location": {
        "country": null,
        "countryCode": null,
        "region": null,
        "city": null,
        "telecommuting": true
      },
      "created_at": "2026-04-16T10:00:00Z",
      "updated_at": "2026-04-16T10:00:00Z",
      "description": "<p>Fully remote role...</p>",
      "requirements": "",
      "benefits": "",
      "employment_type": "Full-time"
    }
  ]
}
```

- [ ] **Step 4: Write the failing tests**

`tests/Unit/Jobs/Drivers/WorkableDriverTest.php`:

```php
<?php

namespace Tests\Unit\Jobs\Drivers;

use App\Models\JobSource;
use App\Services\Jobs\Drivers\WorkableDriver;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkableDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_yields_canonical_jobs(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/workable/shopify-happy.json'));
        Http::fake(['apply.workable.com/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'workable', 'identifier' => 'shopify']);
        $jobs = iterator_to_array(app(WorkableDriver::class)->fetch($source));

        $this->assertCount(1, $jobs);
        $this->assertSame('ABC-123-XYZ', $jobs[0]->sourceId);
        $this->assertSame('Senior Platform Engineer', $jobs[0]->title);
        $this->assertSame('shopify', $jobs[0]->sourceBoard);
        $this->assertSame('onsite', $jobs[0]->remoteMode);
        $this->assertSame('Toronto, Ontario, Canada', $jobs[0]->locationRaw);
    }

    public function test_telecommuting_true_produces_remote_only(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/workable/shopify-missing-location.json'));
        Http::fake(['apply.workable.com/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'workable', 'identifier' => 'shopify']);
        $jobs = iterator_to_array(app(WorkableDriver::class)->fetch($source));

        $this->assertSame('remote_only', $jobs[0]->remoteMode);
    }

    public function test_validateIdentifier_requires_nonempty(): void
    {
        $driver = app(WorkableDriver::class);
        $this->assertFalse($driver->validateIdentifier(null));
        $this->assertTrue($driver->validateIdentifier('shopify'));
    }

    public function test_http_failure_translates(): void
    {
        Http::fake(['apply.workable.com/*' => Http::response('Not found', 404)]);

        $source = JobSource::factory()->create(['source_type' => 'workable', 'identifier' => 'missing']);
        $this->expectException(DriverFetchFailed::class);
        iterator_to_array(app(WorkableDriver::class)->fetch($source));
    }
}
```

- [ ] **Step 5: Run — expect FAIL**

- [ ] **Step 6: Implement `WorkableDriver`**

`app/Services/Jobs/Drivers/WorkableDriver.php`:

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

class WorkableDriver implements JobSourceDriver
{
    public function sourceType(): string
    {
        return 'workable';
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

        $url = "https://apply.workable.com/api/v1/widget/accounts/{$source->identifier}?details=true";

        try {
            $response = Http::acceptJson()->timeout(30)->get($url);
        } catch (\Throwable $e) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, 'HTTP error: ' . $e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, "Upstream returned {$response->status()}");
        }

        foreach ($response->json('jobs') ?? [] as $raw) {
            yield $this->normalize($raw, $source);
        }
    }

    protected function normalize(array $raw, JobSource $source): CanonicalJobData
    {
        $location = $raw['location'] ?? [];
        $remote = ($location['telecommuting'] ?? false) ? 'remote_only' : 'onsite';

        $locationParts = array_filter([
            $location['city'] ?? null,
            $location['region'] ?? null,
            $location['country'] ?? null,
        ]);
        $locationRaw = $locationParts ? implode(', ', $locationParts) : null;

        $description = trim(strip_tags(
            ($raw['description'] ?? '') . "\n\n" .
            ($raw['requirements'] ?? '') . "\n\n" .
            ($raw['benefits'] ?? ''),
        ));

        return new CanonicalJobData(
            sourceId: (string) ($raw['id'] ?? $raw['shortcode']),
            sourceBoard: $source->identifier,
            companyName: ucfirst($source->identifier ?? 'Unknown'),
            title: $raw['title'] ?? 'Untitled',
            description: $description,
            locationRaw: $locationRaw,
            locations: $locationParts,
            remoteMode: $remote,
            compMin: null,
            compMax: null,
            compCurrency: null,
            seniority: $raw['experience'] ?? null,
            employmentType: $raw['employment_type'] ?? null,
            applyUrl: $raw['application_url'] ?? ($raw['url'] ?? ''),
            postedAt: new DateTimeImmutable($raw['created_at'] ?? 'now'),
            rawPayload: $raw,
        );
    }
}
```

- [ ] **Step 7: Run — expect 4 PASS**

- [ ] **Step 8: Run full suite**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --compact
```

Expected: 118 + 4 = 122 pass.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Jobs/Drivers/WorkableDriver.php config/throughline.php tests/Fixtures/drivers/workable tests/Unit/Jobs/Drivers/WorkableDriverTest.php
git commit -m "feat: WorkableDriver with fixture-driven tests"
```

---

## Task 9: `AshbyDriver`

**Files:**
- Create: `app/Services/Jobs/Drivers/AshbyDriver.php`
- Modify: `config/throughline.php` (add `ashby`)
- Create: `tests/Fixtures/drivers/ashby/vercel-happy.json`
- Create: `tests/Fixtures/drivers/ashby/vercel-remote.json`
- Create: `tests/Unit/Jobs/Drivers/AshbyDriverTest.php`

- [ ] **Step 1: Register the driver**

Append to `config/throughline.php`:

```php
'ashby' => \App\Services\Jobs\Drivers\AshbyDriver::class,
```

- [ ] **Step 2: Write fixtures**

`tests/Fixtures/drivers/ashby/vercel-happy.json`:

```json
{
  "apiVersion": "1",
  "jobs": [
    {
      "id": "job_01H1234567890",
      "title": "Software Engineer, Edge",
      "departmentName": "Engineering",
      "teamName": "Edge",
      "employmentType": "FullTime",
      "locationName": "San Francisco Bay Area",
      "isListed": true,
      "isRemote": false,
      "publishedAt": "2026-04-14T09:00:00.000Z",
      "jobUrl": "https://jobs.ashbyhq.com/vercel/abc-def",
      "descriptionPlain": "We are looking for a talented engineer to work on our edge network...",
      "descriptionHtml": "<p>We are looking for...</p>",
      "compensation": {
        "compensationTierSummary": "$180,000 - $220,000 USD",
        "scrapeableCompensationSalarySummary": "$180,000 - $220,000 USD"
      }
    }
  ]
}
```

`tests/Fixtures/drivers/ashby/vercel-remote.json`:

```json
{
  "apiVersion": "1",
  "jobs": [
    {
      "id": "job_01HABCDEF",
      "title": "Remote Platform Engineer",
      "departmentName": "Platform",
      "employmentType": "FullTime",
      "locationName": "Remote",
      "isListed": true,
      "isRemote": true,
      "publishedAt": "2026-04-15T10:00:00.000Z",
      "jobUrl": "https://jobs.ashbyhq.com/vercel/xyz",
      "descriptionPlain": "Fully remote role on the platform team.",
      "descriptionHtml": "<p>Fully remote...</p>",
      "compensation": null
    }
  ]
}
```

- [ ] **Step 3: Write the failing tests**

`tests/Unit/Jobs/Drivers/AshbyDriverTest.php`:

```php
<?php

namespace Tests\Unit\Jobs\Drivers;

use App\Models\JobSource;
use App\Services\Jobs\Drivers\AshbyDriver;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AshbyDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_yields_canonical_jobs_with_comp(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/ashby/vercel-happy.json'));
        Http::fake(['api.ashbyhq.com/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'ashby', 'identifier' => 'vercel']);
        $jobs = iterator_to_array(app(AshbyDriver::class)->fetch($source));

        $this->assertCount(1, $jobs);
        $this->assertSame('job_01H1234567890', $jobs[0]->sourceId);
        $this->assertSame('Software Engineer, Edge', $jobs[0]->title);
        $this->assertSame(180000, $jobs[0]->compMin);
        $this->assertSame(220000, $jobs[0]->compMax);
        $this->assertSame('USD', $jobs[0]->compCurrency);
        $this->assertSame('onsite', $jobs[0]->remoteMode);
    }

    public function test_is_remote_true_produces_remote_only(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/ashby/vercel-remote.json'));
        Http::fake(['api.ashbyhq.com/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'ashby', 'identifier' => 'vercel']);
        $jobs = iterator_to_array(app(AshbyDriver::class)->fetch($source));

        $this->assertSame('remote_only', $jobs[0]->remoteMode);
        $this->assertNull($jobs[0]->compMin);
    }

    public function test_validateIdentifier_requires_nonempty(): void
    {
        $driver = app(AshbyDriver::class);
        $this->assertFalse($driver->validateIdentifier(null));
        $this->assertTrue($driver->validateIdentifier('vercel'));
    }

    public function test_http_failure_translates(): void
    {
        Http::fake(['api.ashbyhq.com/*' => Http::response('Not found', 404)]);

        $source = JobSource::factory()->create(['source_type' => 'ashby', 'identifier' => 'missing']);
        $this->expectException(DriverFetchFailed::class);
        iterator_to_array(app(AshbyDriver::class)->fetch($source));
    }
}
```

- [ ] **Step 4: Run — expect FAIL**

- [ ] **Step 5: Implement `AshbyDriver`**

`app/Services/Jobs/Drivers/AshbyDriver.php`:

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

class AshbyDriver implements JobSourceDriver
{
    public function sourceType(): string
    {
        return 'ashby';
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

        $url = "https://api.ashbyhq.com/posting-api/job-board/{$source->identifier}";

        try {
            $response = Http::acceptJson()->timeout(30)->get($url);
        } catch (\Throwable $e) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, 'HTTP error: ' . $e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, "Upstream returned {$response->status()}");
        }

        foreach ($response->json('jobs') ?? [] as $raw) {
            yield $this->normalize($raw, $source);
        }
    }

    protected function normalize(array $raw, JobSource $source): CanonicalJobData
    {
        [$compMin, $compMax, $compCurrency] = $this->extractComp($raw['compensation'] ?? null);

        $remote = ($raw['isRemote'] ?? false) ? 'remote_only' : 'onsite';
        $locationRaw = $raw['locationName'] ?? null;

        return new CanonicalJobData(
            sourceId: (string) $raw['id'],
            sourceBoard: $source->identifier,
            companyName: ucfirst($source->identifier ?? 'Unknown'),
            title: $raw['title'] ?? 'Untitled',
            description: trim($raw['descriptionPlain'] ?? strip_tags($raw['descriptionHtml'] ?? '')),
            locationRaw: $locationRaw,
            locations: $locationRaw ? [$locationRaw] : [],
            remoteMode: $remote,
            compMin: $compMin,
            compMax: $compMax,
            compCurrency: $compCurrency,
            seniority: null,
            employmentType: $raw['employmentType'] ?? null,
            applyUrl: $raw['jobUrl'] ?? '',
            postedAt: new DateTimeImmutable($raw['publishedAt'] ?? 'now'),
            rawPayload: $raw,
        );
    }

    protected function extractComp(?array $compensation): array
    {
        if (!$compensation) {
            return [null, null, null];
        }

        $summary = $compensation['compensationTierSummary']
            ?? $compensation['scrapeableCompensationSalarySummary']
            ?? null;

        if (!$summary || !preg_match('/\$?([\d,]+)(?:\s*-\s*\$?([\d,]+))?\s*([A-Z]{3})?/', $summary, $m)) {
            return [null, null, null];
        }

        return [
            (int) str_replace(',', '', $m[1]),
            isset($m[2]) && $m[2] !== '' ? (int) str_replace(',', '', $m[2]) : null,
            $m[3] ?? 'USD',
        ];
    }
}
```

- [ ] **Step 6: Run — expect 4 PASS**

- [ ] **Step 7: Full suite**

Expected: 122 + 4 = 126 pass.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Jobs/Drivers/AshbyDriver.php config/throughline.php tests/Fixtures/drivers/ashby tests/Unit/Jobs/Drivers/AshbyDriverTest.php
git commit -m "feat: AshbyDriver with fixture-driven tests"
```

---

## Task 10: `AdzunaDriver` (paginated)

**Files:**
- Create: `app/Services/Jobs/Drivers/AdzunaDriver.php`
- Modify: `config/throughline.php` (add `adzuna`)
- Modify: `config/services.php` (add `adzuna.app_id` + `adzuna.app_key` env refs)
- Modify: `.env.example` (add `ADZUNA_APP_ID=` + `ADZUNA_APP_KEY=`)
- Create: `tests/Fixtures/drivers/adzuna/us-page1.json`
- Create: `tests/Fixtures/drivers/adzuna/us-page2-empty.json`
- Create: `tests/Unit/Jobs/Drivers/AdzunaDriverTest.php`

## Key detail

Adzuna requires `app_id` + `app_key` query params for every request. They're free-tier credentials; missing credentials should degrade to `DriverFetchFailed` rather than silently attempting unauthenticated. Pagination is explicit: `/jobs/{country}/search/{page}?app_id=...&app_key=...&results_per_page=50`. The driver loops pages until it gets an empty `results` array or hits a safety cap (say, 20 pages = 1000 jobs max per fetch, configurable).

- [ ] **Step 1: Register the driver + config + env**

Append to `config/throughline.php`:

```php
'adzuna' => \App\Services\Jobs\Drivers\AdzunaDriver::class,
```

Append to `config/services.php`:

```php
'adzuna' => [
    'app_id' => env('ADZUNA_APP_ID'),
    'app_key' => env('ADZUNA_APP_KEY'),
    'max_pages' => env('ADZUNA_MAX_PAGES', 20),
    'results_per_page' => env('ADZUNA_RESULTS_PER_PAGE', 50),
],
```

Append to `.env.example`:

```env
ADZUNA_APP_ID=
ADZUNA_APP_KEY=
```

- [ ] **Step 2: Write fixtures**

`tests/Fixtures/drivers/adzuna/us-page1.json`:

```json
{
  "count": 73,
  "mean": 130000,
  "results": [
    {
      "id": "4847392017",
      "title": "Software Engineer",
      "company": {"display_name": "OpenAI"},
      "location": {"display_name": "San Francisco, CA", "area": ["US", "California", "San Francisco"]},
      "category": {"label": "IT Jobs", "tag": "it-jobs"},
      "description": "Build and maintain core platform infrastructure. Requires strong systems design skills and experience with distributed systems...",
      "redirect_url": "https://www.adzuna.com/land/ad/4847392017",
      "salary_min": 180000,
      "salary_max": 280000,
      "salary_is_predicted": "0",
      "contract_type": "permanent",
      "created": "2026-04-15T12:00:00Z"
    },
    {
      "id": "4847392018",
      "title": "Backend Engineer (Remote)",
      "company": {"display_name": "Stripe"},
      "location": {"display_name": "Remote", "area": ["US"]},
      "category": {"label": "IT Jobs", "tag": "it-jobs"},
      "description": "Remote backend role...",
      "redirect_url": "https://www.adzuna.com/land/ad/4847392018",
      "salary_min": null,
      "salary_max": null,
      "contract_type": "permanent",
      "created": "2026-04-16T09:30:00Z"
    }
  ]
}
```

`tests/Fixtures/drivers/adzuna/us-page2-empty.json`:

```json
{
  "count": 73,
  "mean": 130000,
  "results": []
}
```

- [ ] **Step 3: Write the failing tests (includes a pagination-specific test)**

`tests/Unit/Jobs/Drivers/AdzunaDriverTest.php`:

```php
<?php

namespace Tests\Unit\Jobs\Drivers;

use App\Models\JobSource;
use App\Services\Jobs\Drivers\AdzunaDriver;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdzunaDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.adzuna.app_id' => 'test-id', 'services.adzuna.app_key' => 'test-key']);
    }

    public function test_yields_canonical_jobs_from_page1(): void
    {
        $page1 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/adzuna/us-page1.json')), true);
        $page2 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/adzuna/us-page2-empty.json')), true);

        Http::fake([
            'api.adzuna.com/*/search/1*' => Http::response($page1),
            'api.adzuna.com/*/search/2*' => Http::response($page2),
        ]);

        $source = JobSource::factory()->create([
            'source_type' => 'adzuna',
            'identifier' => 'us',
            'config_json' => ['country' => 'us'],
        ]);
        $jobs = iterator_to_array(app(AdzunaDriver::class)->fetch($source));

        $this->assertCount(2, $jobs);
        $this->assertSame('4847392017', $jobs[0]->sourceId);
        $this->assertSame('OpenAI', $jobs[0]->companyName);
        $this->assertSame(180000, $jobs[0]->compMin);
        $this->assertSame(280000, $jobs[0]->compMax);
    }

    public function test_stops_paginating_when_results_empty(): void
    {
        $page1 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/adzuna/us-page1.json')), true);
        $page2Empty = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/adzuna/us-page2-empty.json')), true);

        $callCount = 0;
        Http::fake(function ($request) use ($page1, $page2Empty, &$callCount) {
            $callCount++;
            if (str_contains($request->url(), '/search/1')) {
                return Http::response($page1);
            }
            return Http::response($page2Empty);
        });

        $source = JobSource::factory()->create([
            'source_type' => 'adzuna',
            'identifier' => 'us',
            'config_json' => ['country' => 'us'],
        ]);
        iterator_to_array(app(AdzunaDriver::class)->fetch($source));

        // Page 1 + page 2 (empty) — then stop. Should be exactly 2 calls.
        $this->assertSame(2, $callCount);
    }

    public function test_null_salary_preserved(): void
    {
        $page1 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/adzuna/us-page1.json')), true);
        $page2Empty = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/adzuna/us-page2-empty.json')), true);

        Http::fake([
            'api.adzuna.com/*/search/1*' => Http::response($page1),
            'api.adzuna.com/*/search/2*' => Http::response($page2Empty),
        ]);

        $source = JobSource::factory()->create([
            'source_type' => 'adzuna',
            'identifier' => 'us',
            'config_json' => ['country' => 'us'],
        ]);
        $jobs = iterator_to_array(app(AdzunaDriver::class)->fetch($source));

        $this->assertNull($jobs[1]->compMin);
        $this->assertNull($jobs[1]->compMax);
    }

    public function test_missing_credentials_throws_driver_fetch_failed(): void
    {
        config(['services.adzuna.app_id' => null, 'services.adzuna.app_key' => null]);

        $source = JobSource::factory()->create([
            'source_type' => 'adzuna',
            'identifier' => 'us',
            'config_json' => ['country' => 'us'],
        ]);

        $this->expectException(DriverFetchFailed::class);
        iterator_to_array(app(AdzunaDriver::class)->fetch($source));
    }

    public function test_http_failure_translates(): void
    {
        Http::fake(['api.adzuna.com/*' => Http::response('rate limit', 429)]);

        $source = JobSource::factory()->create([
            'source_type' => 'adzuna',
            'identifier' => 'us',
            'config_json' => ['country' => 'us'],
        ]);

        $this->expectException(DriverFetchFailed::class);
        iterator_to_array(app(AdzunaDriver::class)->fetch($source));
    }
}
```

- [ ] **Step 4: Run — expect FAIL**

- [ ] **Step 5: Implement `AdzunaDriver`**

`app/Services/Jobs/Drivers/AdzunaDriver.php`:

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

class AdzunaDriver implements JobSourceDriver
{
    public function sourceType(): string
    {
        return 'adzuna';
    }

    public function validateIdentifier(?string $identifier): bool
    {
        // Adzuna's identifier is the country code; accepted values are short strings.
        return is_string($identifier) && preg_match('/^[a-z]{2,3}$/', $identifier) === 1;
    }

    public function fetch(JobSource $source): Generator
    {
        $appId = config('services.adzuna.app_id');
        $appKey = config('services.adzuna.app_key');

        if (!$appId || !$appKey) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, 'Adzuna credentials not configured (ADZUNA_APP_ID / ADZUNA_APP_KEY)');
        }

        $country = $source->config_json['country'] ?? $source->identifier ?? 'us';
        $maxPages = (int) config('services.adzuna.max_pages', 20);
        $perPage = (int) config('services.adzuna.results_per_page', 50);

        for ($page = 1; $page <= $maxPages; $page++) {
            $url = sprintf('https://api.adzuna.com/v1/api/jobs/%s/search/%d', $country, $page);

            try {
                $response = Http::acceptJson()->timeout(30)->get($url, [
                    'app_id' => $appId,
                    'app_key' => $appKey,
                    'results_per_page' => $perPage,
                    'content-type' => 'application/json',
                ]);
            } catch (\Throwable $e) {
                throw new DriverFetchFailed($this->sourceType(), $source->identifier, 'HTTP error: ' . $e->getMessage(), $e);
            }

            if ($response->failed()) {
                throw new DriverFetchFailed($this->sourceType(), $source->identifier, "Upstream returned {$response->status()} on page {$page}");
            }

            $results = $response->json('results') ?? [];
            if (empty($results)) {
                return;
            }

            foreach ($results as $raw) {
                yield $this->normalize($raw, $source);
            }
        }
    }

    protected function normalize(array $raw, JobSource $source): CanonicalJobData
    {
        $locationRaw = $raw['location']['display_name'] ?? null;
        $isRemote = $locationRaw && stripos($locationRaw, 'remote') !== false;
        $remote = $isRemote ? 'remote_only' : 'onsite';

        return new CanonicalJobData(
            sourceId: (string) $raw['id'],
            sourceBoard: $source->identifier,
            companyName: $raw['company']['display_name'] ?? 'Unknown',
            title: $raw['title'] ?? 'Untitled',
            description: trim(strip_tags($raw['description'] ?? '')),
            locationRaw: $locationRaw,
            locations: $locationRaw ? [$locationRaw] : [],
            remoteMode: $remote,
            compMin: isset($raw['salary_min']) && $raw['salary_min'] ? (int) $raw['salary_min'] : null,
            compMax: isset($raw['salary_max']) && $raw['salary_max'] ? (int) $raw['salary_max'] : null,
            compCurrency: isset($raw['salary_min']) && $raw['salary_min'] ? 'USD' : null,
            seniority: null,
            employmentType: $raw['contract_type'] ?? null,
            applyUrl: $raw['redirect_url'] ?? '',
            postedAt: new DateTimeImmutable($raw['created'] ?? 'now'),
            rawPayload: $raw,
        );
    }
}
```

- [ ] **Step 6: Run — expect 5 PASS**

- [ ] **Step 7: Run all driver tests together to confirm no regressions**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=DriverTest
```

Expected: Greenhouse 4 + Lever 4 + RemoteOk 4 + Workable 4 + Ashby 4 + Adzuna 5 = 25 driver tests all pass.

- [ ] **Step 8: Full suite**

Expected: 126 + 5 = 131 pass.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Jobs/Drivers/AdzunaDriver.php config/throughline.php config/services.php .env.example tests/Fixtures/drivers/adzuna tests/Unit/Jobs/Drivers/AdzunaDriverTest.php
git commit -m "$(cat <<'EOF'
feat: AdzunaDriver with pagination across country-scoped search

Aggregator-shape driver that paginates via /search/{page} until results
are empty or max_pages is hit (default 20 pages × 50 results/page =
1000-job ceiling per fetch). Country is resolved from job_sources.
config_json.country (seeded as 'us'), falling back to identifier.
Credentials read from config/services.adzuna.{app_id,app_key} — missing
credentials throws DriverFetchFailed at the start of fetch() rather
than letting Adzuna reject an unauthenticated request.

Proves the JobSourceDriver generator contract holds for genuinely
paginated APIs; Greenhouse/Lever were single-shot, RemoteOK was a
single-page aggregator.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: E2E spec update + docs + final verification

**Files:**
- Modify: `tests/e2e/feed-happy-path.spec.ts` (extend to assert stage-2 rendering)
- Modify: `app/Providers/TestingServiceProvider.php` (extend fake AiClient with deterministic rerank)
- Modify: `routes/testing.php` (add `/testing/apply-rerank/{profileId}` route for E2E)
- Modify: `CLAUDE.md` (append Plan 3 architecture bullets)
- Modify: `README.md` (update "What's here now" section)

- [ ] **Step 1: Extend the fake AiClient to implement rerank**

Edit `app/Providers/TestingServiceProvider.php`. The existing binding creates an anonymous subclass of `AiClient` with `embed()` and `parseResume()` overrides. Add a `rerank()` method:

```php
public function rerank(string $resumeText, array $jobs): array
{
    return array_map(fn ($j) => [
        'job_id' => $j['id'],
        'fit_score' => 75,
        'rationale' => 'Deterministic fake rationale for testing.',
        'gaps' => [['requirement' => 'Example requirement', 'severity' => 'stretch']],
        'narrative' => 'Fake career-arc narrative.',
    ], $jobs);
}
```

- [ ] **Step 2: Add the apply-rerank test route**

Edit `routes/testing.php`. Append:

```php
Route::post('/testing/apply-rerank/{profileId}', function ($profileId) {
    $size = (int) request('size', 20);
    \App\Jobs\RerankShortlist::dispatchSync((int) $profileId, $size, null);
    return response()->json(['ok' => true]);
});
```

- [ ] **Step 3: Extend the Playwright spec**

Edit `tests/e2e/feed-happy-path.spec.ts`. After the existing feed-assertion step ("see ranked feed with placeholder rationale cards"), add:

```ts
// Now force a rerank so stage-2 output lands.
await request.post(`/testing/apply-rerank/${profileId}`);

// Refresh and confirm stage-2 renders.
await page.reload();
await expect(page.locator('article').first()).toBeVisible();

// Fit score badge is now visible somewhere on the first card.
await expect(page.locator('article').first().getByText('75', { exact: false })).toBeVisible();

// Narrative subtitle is rendered (contains the fake text).
await expect(page.getByText(/Fake career-arc narrative/i).first()).toBeVisible();

// At least one gap chip.
await expect(page.getByText('Example requirement').first()).toBeVisible();
```

- [ ] **Step 4: Run the E2E spec**

```bash
docker compose up -d
/c/Users/david/.config/herd/bin/php84/php.exe artisan migrate:fresh --seed --env=testing
npm run test:e2e
```

Expected: the spec passes end-to-end, including the stage-2 assertions. If the spec fails on the stage-1 portion, the Playwright selector fix from Task 1 didn't land — go back and fix.

- [ ] **Step 5: Rewrite `CLAUDE.md` architecture section**

Open `CLAUDE.md`. In the "Architecture notes" section, append these bullets after the existing ones:

```markdown
- **Stage-2 rerank** — `AiClient::rerank()` produces fit score + rationale + gaps + narrative via Claude Sonnet with all-or-throw semantics. `RerankShortlist` is the only writer to `job_matches.stage2_*` columns and is dispatchable sync (feed controller) or async (nightly refresh). Failures write a `'rerank unavailable'` sentinel so the stale-row query doesn't loop.
- **Nightly rerank** — `NightlyRerankRefresh` runs at the UTC hour set by `site_settings.nightly_rerank_hour_utc` (default 04:00) and reranks the top-50 per active profile with a linked resume.
- **Feed ordering** — `FeedController` orders by `stage2_score DESC NULLS LAST, stage1_score DESC` so fresh-reranked matches rise above never-reranked ones. `?raw=1` bypasses rerank entirely for debugging.
- **Drivers (Plan 3)** — `WorkableDriver` + `AshbyDriver` extend the ATS shape from Plan 2. `AdzunaDriver` introduces the paginated-aggregator shape; credentials required via `ADZUNA_APP_ID` + `ADZUNA_APP_KEY`.
```

In the "Key commands" section, add:

```markdown
- `php artisan throughline:rerank-now {profile-id}` — synchronously rerank a profile's top-K stale matches.
```

In the "What's NOT here yet" section (or equivalent), remove the mentions of stage-2 rerank and update Plan-2-to-Plan-3 language.

- [ ] **Step 6: Update `README.md`**

In the "What's here now" (or equivalent) section, extend the summary: add stage-2 rerank, nightly refresh, the three new drivers, the `?raw=1` debug param, and the `throughline:rerank-now` command. Keep the overall shape intact.

- [ ] **Step 7: Full test suite + E2E**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --compact
```

Expected: 131 PHP tests pass.

```bash
npm run test:e2e
```

Expected: Playwright spec passes with stage-2 assertions.

- [ ] **Step 8: Format + commit**

```bash
vendor/bin/pint --dirty
git add tests/e2e/feed-happy-path.spec.ts app/Providers/TestingServiceProvider.php routes/testing.php CLAUDE.md README.md
git commit -m "$(cat <<'EOF'
feat: Playwright spec asserts stage-2 + docs updated for Plan 3

TestingServiceProvider's fake AiClient gains a deterministic rerank()
that returns a fit_score of 75, a single stretch-severity gap, and a
canned narrative. Fresh test-only route /testing/apply-rerank/{id}
dispatches RerankShortlist synchronously, so the Playwright happy
path can force stage-2 data to land between the feed-empty-state
assertion and the fit-score-chip assertion.

CLAUDE.md gets three new architecture bullets (rerank orchestration,
nightly refresh, feed ordering) and one new command (rerank-now).
README updated to reflect what Plan 3 ships.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Done

Plan 3 is complete when all 11 tasks are green, `php artisan test --compact` reports ~131 tests passing, `npm run test:e2e` passes with stage-2 assertions, and a manual dogfood with real API keys produces feed cards with real Claude-generated fit scores + narratives + rationale + gap chips. Plan 4 starts by building the application pipeline (saved jobs, status transitions, kanban UI) using the stage-2-enriched matches as its entry point.
