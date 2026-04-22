# Throughline — Plan 5: Admin dashboard

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give operators one place to see what's happening, what's being spent, and what to fix. Overview cards, paginated Users/Jobs/Matches browsers, analytics charts (including the AI-cost-by-profile chart Plan 3 Task 2's `context_id` fix enabled), settings editor, audit log, error log with retry, one-level impersonation, Horizon mount.

**Architecture:** New `admin_activity_log` table (append-only, same boot-guard pattern as Plan 4's `application_events`). `AdminLogger` service is the single writer. All admin controllers extend `Admin\BaseController` which applies `EnsureAdmin` + exposes `$this->audit(...)`. Analytics service produces Chart.js-ready arrays; page-side uses `vue-chartjs`. Impersonation flips `Auth::login` while stashing original admin id in session; a persistent banner surfaces from an Inertia shared prop.

**Tech Stack:** New npm: `chart.js@^4.4.0` + `vue-chartjs@^5.3.0`. No new composer packages (Horizon already installed). Same Laravel 12 + PHP 8.4 + Postgres + Redis stack.

**Spec reference:** [`docs/superpowers/specs/2026-04-22-throughline-plan-5-design.md`](../specs/2026-04-22-throughline-plan-5-design.md)

---

## Preconditions

- Plan 4 merged to main. 178 PHP tests + Playwright E2E passing baseline.
- Branch `plan-5-admin-dashboard` forked from main.
- Docker stack running.

---

## Task index

1. `admin_activity_log` table + model + `AdminLogger` service + `Admin\BaseController` + tests
2. `AdminLayout` + admin nav link + Overview page + OverviewController + tests
3. Admin Users list + detail + role edit + deactivate + tests
4. Impersonation start/stop + persistent banner via Inertia shared prop + tests
5. Admin Jobs browser (read-only) + detail + filters + tests
6. Analytics service + page + Chart.js integration + tests
7. Admin Settings editor (env-secret masking + type validation) + tests
8. Admin Audit log viewer with filters + tests
9. Admin Error log + retry actions + tests
10. Horizon mount + E2E spec extension + docs + final verification

---

## Task 1: `admin_activity_log` + `AdminLogger` + `Admin\BaseController`

**Files:**
- Create: `database/migrations/2026_04_22_300000_create_admin_activity_log_table.php`
- Create: `app/Models/AdminActivityLog.php`
- Create: `database/factories/AdminActivityLogFactory.php`
- Create: `app/Services/Admin/AdminLogger.php`
- Create: `app/Http/Controllers/Admin/BaseController.php`
- Create: `tests/Feature/Admin/AdminActivityLogAppendOnlyTest.php`
- Create: `tests/Feature/Admin/AdminLoggerTest.php`

- [ ] **Step 1: Migration**

`database/migrations/2026_04_22_300000_create_admin_activity_log_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('admin_activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 64);
            $table->string('target_type', 64)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->jsonb('payload')->default('{}');
            $table->string('ip', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['admin_user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activity_log');
    }
};
```

- [ ] **Step 2: Model**

`app/Models/AdminActivityLog.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AdminActivityLog extends Model
{
    use HasFactory;

    protected $table = 'admin_activity_log';
    public $timestamps = false;

    protected $fillable = ['admin_user_id', 'action', 'target_type', 'target_id', 'payload', 'ip', 'created_at'];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('admin_activity_log rows are append-only.');
        });
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
```

- [ ] **Step 3: Factory**

`database/factories/AdminActivityLogFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AdminActivityLogFactory extends Factory
{
    protected $model = AdminActivityLog::class;

    public function definition(): array
    {
        return [
            'admin_user_id' => User::factory()->state(['role' => 'admin']),
            'action' => 'test.action',
            'target_type' => null,
            'target_id' => null,
            'payload' => [],
            'ip' => '127.0.0.1',
            'created_at' => now(),
        ];
    }
}
```

- [ ] **Step 4: Tests**

`tests/Feature/Admin/AdminActivityLogAppendOnlyTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AdminActivityLogAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_a_row_throws(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $log = AdminActivityLog::create([
            'admin_user_id' => $admin->id,
            'action' => 'test.action',
            'payload' => [],
            'ip' => '127.0.0.1',
            'created_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $log->update(['action' => 'different']);
    }
}
```

`tests/Feature/Admin/AdminLoggerTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\AdminActivityLog;
use App\Models\User;
use App\Services\Admin\AdminLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_with_eloquent_target_captures_type_and_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create();

        $log = app(AdminLogger::class)->record($admin, 'user.role_changed', $target, ['from' => 'user', 'to' => 'admin'], '127.0.0.1');

        $this->assertSame('user.role_changed', $log->action);
        $this->assertSame('User', $log->target_type);
        $this->assertSame($target->id, $log->target_id);
        $this->assertSame(['from' => 'user', 'to' => 'admin'], $log->payload);
        $this->assertSame($admin->id, $log->admin_user_id);
        $this->assertSame('127.0.0.1', $log->ip);
    }

    public function test_record_with_null_target_omits_type_and_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $log = app(AdminLogger::class)->record($admin, 'system.maintenance_toggled', null, ['enabled' => true]);

        $this->assertNull($log->target_type);
        $this->assertNull($log->target_id);
    }

    public function test_record_with_string_target_uses_string_as_type(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $log = app(AdminLogger::class)->record($admin, 'site_setting.updated', 'SiteSetting:rerank_enabled', ['old' => 'true', 'new' => 'false']);

        $this->assertSame('SiteSetting:rerank_enabled', $log->target_type);
        $this->assertNull($log->target_id);
    }

    public function test_logger_never_throws_even_on_db_write_failure(): void
    {
        // Simulate by passing an admin with a broken relationship — ensure the call doesn't bubble up.
        $admin = new User(); // unsaved, has no id
        $admin->id = 999999; // nonexistent; FK will reject

        // Should NOT throw — should log a warning and return null.
        $result = app(AdminLogger::class)->record($admin, 'will.fail');
        $this->assertNull($result);
        $this->assertSame(0, AdminActivityLog::count());
    }
}
```

- [ ] **Step 5: Run — expect FAIL**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter="AdminActivityLogAppendOnlyTest|AdminLoggerTest"
```

- [ ] **Step 6: Implement `AdminLogger`**

`app/Services/Admin/AdminLogger.php`:

```php
<?php

namespace App\Services\Admin;

use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class AdminLogger
{
    public function record(User $admin, string $action, mixed $target = null, array $payload = [], ?string $ip = null): ?AdminActivityLog
    {
        [$targetType, $targetId] = $this->resolveTarget($target);

        try {
            return AdminActivityLog::create([
                'admin_user_id' => $admin->id,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'payload' => $payload,
                'ip' => $ip,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AdminLogger::record failed', [
                'admin_user_id' => $admin->id ?? null,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function resolveTarget(mixed $target): array
    {
        if ($target === null) {
            return [null, null];
        }
        if ($target instanceof Model) {
            return [class_basename($target), $target->getKey()];
        }
        if (is_string($target)) {
            return [$target, null];
        }
        return [null, null];
    }
}
```

- [ ] **Step 7: Implement `Admin\BaseController`**

`app/Http/Controllers/Admin/BaseController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAdmin;
use App\Models\AdminActivityLog;
use App\Services\Admin\AdminLogger;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as RoutingController;

class BaseController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(EnsureAdmin::class);
    }

    protected function audit(string $action, mixed $target = null, array $payload = []): ?AdminActivityLog
    {
        /** @var \App\Models\User|null $admin */
        $admin = auth()->user();
        if (!$admin) {
            return null;
        }

        return app(AdminLogger::class)->record($admin, $action, $target, $payload, request()->ip());
    }
}
```

Note: Laravel 11+ controller middleware is registered differently than older versions — if `$this->middleware(...)` isn't available on the `Controller` base, use route-level middleware application instead (wrap admin routes in a group with `EnsureAdmin` middleware, and the BaseController just provides the `audit()` helper).

- [ ] **Step 8: Run tests — expect PASS**

Expected: 5 tests pass (1 append-only + 4 AdminLogger). Full suite: 178 + 5 = 183.

- [ ] **Step 9: Migrate**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan migrate --no-interaction
```

- [ ] **Step 10: Format + commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_04_22_300000_create_admin_activity_log_table.php app/Models/AdminActivityLog.php database/factories/AdminActivityLogFactory.php app/Services/Admin/AdminLogger.php app/Http/Controllers/Admin/BaseController.php tests/Feature/Admin/AdminActivityLogAppendOnlyTest.php tests/Feature/Admin/AdminLoggerTest.php
git commit -m "$(cat <<'EOF'
feat: admin_activity_log schema + AdminLogger + Admin\\BaseController

New append-only audit table; Eloquent boot guard throws
LogicException on update (same pattern as Plan 4's
application_events). AdminLogger is the single writer — resolves
target type from class basename + id, accepts null or string
targets, catches DB write failures to warn instead of breaking the
admin's workflow.

Admin\\BaseController applies 'auth' + EnsureAdmin middleware and
exposes $this->audit() so downstream admin controllers call one
method to write their audit rows.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `AdminLayout` + admin nav link + Overview page

**Files:**
- Create: `resources/js/layouts/AdminLayout.vue`
- Modify: `resources/js/components/AppSidebar.vue` (add admin-only "Admin" link visible when user is admin)
- Create: `app/Http/Controllers/Admin/OverviewController.php`
- Create: `resources/js/pages/admin/Overview.vue`
- Modify: `routes/web.php` (add `/admin` route under the existing admin group)
- Create: `tests/Feature/Admin/AdminOverviewTest.php`

- [ ] **Step 1: Tests**

`tests/Feature/Admin/AdminOverviewTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\CanonicalJob;
use App\Models\JobMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_overview_cards_with_aggregates(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(3)->create(['role' => 'user']);
        CanonicalJob::factory()->count(5)->create();

        $response = $this->actingAs($admin)->get(route('admin.overview'));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertArrayHasKey('cards', $props);
        $this->assertGreaterThanOrEqual(4, $props['cards']['total_users']); // 3 users + 1 admin
        $this->assertSame(5, $props['cards']['total_jobs']);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->actingAs($user)->get(route('admin.overview'))->assertForbidden();
    }

    public function test_unauthenticated_redirects_to_login(): void
    {
        $this->get(route('admin.overview'))->assertRedirect(route('login'));
    }
}
```

- [ ] **Step 2: Controller**

`app/Http/Controllers/Admin/OverviewController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Models\Application;
use App\Models\CanonicalJob;
use App\Models\JobMatch;
use App\Models\JobSource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends BaseController
{
    public function show(): Response
    {
        $todayStart = now()->startOfDay();
        $monthStart = now()->startOfMonth();
        $sevenDaysAgo = now()->subDays(7);

        $cards = [
            'total_users' => User::count(),
            'active_7d' => User::where('last_login_at', '>=', $sevenDaysAgo)->count(),
            'total_jobs' => CanonicalJob::count(),
            'jobs_today' => CanonicalJob::where('created_at', '>=', $todayStart)->count(),
            'total_matches' => JobMatch::count(),
            'applications_by_status' => Application::query()
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->toArray(),
            'ai_spend_today' => (float) DB::table('ai_usage_events')->where('created_at', '>=', $todayStart)->sum('estimated_cost_usd'),
            'ai_spend_month' => (float) DB::table('ai_usage_events')->where('created_at', '>=', $monthStart)->sum('estimated_cost_usd'),
            'sources' => JobSource::query()
                ->select('id', 'source_type', 'identifier', 'display_name', 'is_enabled', 'last_fetched_at', 'last_error', 'consecutive_failures')
                ->orderBy('source_type')
                ->get(),
        ];

        return Inertia::render('admin/Overview', ['cards' => $cards]);
    }
}
```

- [ ] **Step 3: Route + middleware group**

Edit `routes/web.php`. The existing `Plan 2 Task 14` admin group wraps `/admin/job-sources`. Reorganize so all admin routes live in one group:

```php
use App\Http\Controllers\Admin\OverviewController;

Route::middleware(['auth', \App\Http\Middleware\EnsureAdmin::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', [OverviewController::class, 'show'])->name('overview');

        // Existing job-sources routes from Plan 2 stay inside this group:
        Route::resource('job-sources', \App\Http\Controllers\Admin\JobSourceController::class)->except('show');
        Route::post('job-sources/{job_source}/fetch-now', [\App\Http\Controllers\Admin\JobSourceController::class, 'fetchNow'])->name('job-sources.fetch-now');
    });
```

(Consolidate; remove the duplicate top-level admin group from Plan 2 Task 14 if it exists as a separate `->middleware(['auth', EnsureAdmin::class])->prefix('admin')` invocation.)

- [ ] **Step 4: `AdminLayout.vue`**

`resources/js/layouts/AdminLayout.vue`:

```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const navItems = [
  { title: 'Overview', href: '/admin', icon: '◔' },
  { title: 'Users', href: '/admin/users', icon: '◑' },
  { title: 'Jobs', href: '/admin/jobs', icon: '◐' },
  { title: 'Sources', href: '/admin/job-sources', icon: '◒' },
  { title: 'Analytics', href: '/admin/analytics', icon: '◕' },
  { title: 'Settings', href: '/admin/settings', icon: '●' },
  { title: 'Audit', href: '/admin/audit', icon: '◉' },
  { title: 'Errors', href: '/admin/errors', icon: '◎' },
  { title: 'Horizon', href: '/admin/horizon', icon: '◯' },
];

const page = usePage();
const currentPath = computed(() => page.url);
</script>

<template>
  <AppLayout>
    <div class="mx-auto flex w-full max-w-7xl gap-6 px-6 py-6">
      <aside class="w-48 shrink-0">
        <h2 class="mb-3 text-xs font-medium uppercase tracking-wider text-ink-3">Admin</h2>
        <nav class="space-y-1">
          <Link
            v-for="item in navItems"
            :key="item.href"
            :href="item.href"
            :class="[
              'block rounded px-2 py-1 text-sm',
              currentPath.startsWith(item.href)
                ? 'bg-bone-2 font-medium text-ink'
                : 'text-ink-2 hover:bg-bone-2/60 hover:text-ink',
            ]"
          >
            <span class="mr-2 text-ink-3">{{ item.icon }}</span>{{ item.title }}
          </Link>
        </nav>
      </aside>

      <main class="min-w-0 flex-1">
        <slot />
      </main>
    </div>
  </AppLayout>
</template>
```

(Simple unicode bullets for icons — no new icon lib. Can be swapped to lucide icons later.)

- [ ] **Step 5: Overview page**

`resources/js/pages/admin/Overview.vue`:

```vue
<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
  cards: {
    total_users: number;
    active_7d: number;
    total_jobs: number;
    jobs_today: number;
    total_matches: number;
    applications_by_status: Record<string, number>;
    ai_spend_today: number;
    ai_spend_month: number;
    sources: Array<{ id: number; source_type: string; identifier: string | null; display_name: string; is_enabled: boolean; last_fetched_at: string | null; last_error: string | null; consecutive_failures: number }>;
  };
}>();

function statusColor(source: { is_enabled: boolean; last_error: string | null; consecutive_failures: number; last_fetched_at: string | null }) {
  if (!source.is_enabled) return 'text-oxblood';
  if (source.last_error || source.consecutive_failures > 0) return 'text-oxblood';
  if (!source.last_fetched_at) return 'text-ink-3';
  const ageMs = Date.now() - new Date(source.last_fetched_at).getTime();
  return ageMs > 1000 * 60 * 60 * 6 ? 'text-ink-3' : 'text-ink-2';
}
</script>

<template>
  <Head title="Admin — Overview" />
  <AdminLayout>
    <h1 class="font-display text-3xl text-ink">Overview</h1>

    <section class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <div class="rounded border border-hairline bg-bone-2/40 px-4 py-3">
        <p class="text-xs text-ink-3">Users</p>
        <p class="mt-1 font-display text-2xl text-ink">{{ cards.total_users }}</p>
        <p class="text-xs text-ink-3">{{ cards.active_7d }} active in 7d</p>
      </div>
      <div class="rounded border border-hairline bg-bone-2/40 px-4 py-3">
        <p class="text-xs text-ink-3">Jobs</p>
        <p class="mt-1 font-display text-2xl text-ink">{{ cards.total_jobs }}</p>
        <p class="text-xs text-ink-3">{{ cards.jobs_today }} today</p>
      </div>
      <div class="rounded border border-hairline bg-bone-2/40 px-4 py-3">
        <p class="text-xs text-ink-3">Matches</p>
        <p class="mt-1 font-display text-2xl text-ink">{{ cards.total_matches }}</p>
      </div>
      <div class="rounded border border-hairline bg-bone-2/40 px-4 py-3">
        <p class="text-xs text-ink-3">AI spend</p>
        <p class="mt-1 font-display text-2xl text-ink">${{ cards.ai_spend_today.toFixed(2) }}</p>
        <p class="text-xs text-ink-3">${{ cards.ai_spend_month.toFixed(2) }} this month</p>
      </div>
    </section>

    <section class="mt-8">
      <h2 class="font-display text-xl text-ink">Applications by status</h2>
      <ul class="mt-3 flex flex-wrap gap-3 text-sm">
        <li v-for="(count, status) in cards.applications_by_status" :key="status" class="rounded-full border border-hairline px-3 py-1 text-ink-2">
          {{ status }}: <span class="font-medium text-ink">{{ count }}</span>
        </li>
        <li v-if="Object.keys(cards.applications_by_status).length === 0" class="text-ink-3">No applications yet.</li>
      </ul>
    </section>

    <section class="mt-8">
      <h2 class="font-display text-xl text-ink">Source health</h2>
      <table class="mt-3 w-full border-collapse text-sm">
        <thead class="text-left text-ink-3">
          <tr>
            <th class="py-2 pr-3">Source</th>
            <th class="py-2 pr-3">Last fetched</th>
            <th class="py-2 pr-3">Failures</th>
            <th class="py-2 pr-3">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-hairline">
          <tr v-for="src in cards.sources" :key="src.id">
            <td class="py-2 pr-3">
              <span class="text-ink">{{ src.display_name }}</span>
              <span class="ml-2 text-xs text-ink-3">{{ src.source_type }}{{ src.identifier ? ' / ' + src.identifier : '' }}</span>
            </td>
            <td class="py-2 pr-3 text-ink-3">{{ src.last_fetched_at ? new Date(src.last_fetched_at).toLocaleString() : 'never' }}</td>
            <td class="py-2 pr-3 text-ink-3">{{ src.consecutive_failures }}</td>
            <td class="py-2 pr-3"><span :class="statusColor(src)">{{ !src.is_enabled ? 'Paused' : src.last_error ? 'Error' : 'OK' }}</span></td>
          </tr>
        </tbody>
      </table>
    </section>
  </AdminLayout>
</template>
```

- [ ] **Step 6: Admin nav link in `AppSidebar.vue`**

Edit `resources/js/components/AppSidebar.vue`. After the existing `mainNavItems`, add an admin-only section. Inspect the existing nav render loop to understand the pattern. Add:

```ts
const adminNavItems: NavItem[] = [
  { title: 'Admin', href: '/admin', icon: ShieldCheck },
];
```

(import `ShieldCheck` from lucide-vue-next)

And render this section only when `$page.props.auth?.user?.role === 'admin'`.

- [ ] **Step 7: Run tests + full suite**

Expected: 3 new tests + 183 baseline = 186 pass.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Admin/OverviewController.php resources/js/layouts/AdminLayout.vue resources/js/pages/admin/Overview.vue resources/js/components/AppSidebar.vue routes/web.php tests/Feature/Admin/AdminOverviewTest.php
git commit -m "$(cat <<'EOF'
feat: admin overview page + AdminLayout + admin nav link

AdminLayout renders a narrow left-hand admin sidebar (Overview,
Users, Jobs, Sources, Analytics, Settings, Audit, Errors, Horizon)
nested inside the user-facing AppLayout. Non-admin users never see
the Admin link — it's gated on role === 'admin' via Inertia shared
props.

OverviewController aggregates: total users + 7d-active, total +
today's canonical_jobs, total matches, applications grouped by
status, AI spend today + month, and source-health rollup with
status coloring. No writes — pure read page.

Routes consolidated under a single admin middleware group with
prefix/name; Plan 2's job-sources routes merged into this group.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Admin Users — list + detail + role edit + deactivate

**Files:**
- Create: `app/Http/Controllers/Admin/UserController.php`
- Create: `resources/js/pages/admin/users/Index.vue`
- Create: `resources/js/pages/admin/users/Show.vue`
- Modify: `routes/web.php` (add user routes inside admin group)
- Modify: `app/Models/User.php` (add `deactivated_at` column via new migration + cast)
- Create: `database/migrations/2026_04_22_300100_add_deactivated_at_to_users.php`
- Create: `tests/Feature/Admin/AdminUserManagementTest.php`

- [ ] **Step 1: Migration for `deactivated_at`**

`database/migrations/2026_04_22_300100_add_deactivated_at_to_users.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable();
            $table->index('deactivated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deactivated_at');
        });
    }
};
```

Update `app/Models/User.php`: add `'deactivated_at'` to `$fillable` and `$casts` (datetime). Add a helper `isDeactivated(): bool { return $this->deactivated_at !== null; }`.

- [ ] **Step 2: Tests**

`tests/Feature/Admin/AdminUserManagementTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\AdminActivityLog;
use App\Models\Application;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_with_search_filters_by_email(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['email' => 'alice@example.com', 'name' => 'Alice']);
        User::factory()->create(['email' => 'bob@example.com', 'name' => 'Bob']);

        $response = $this->actingAs($admin)->get(route('admin.users.index', ['q' => 'alice']));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertCount(1, $props['users']['data']);
        $this->assertSame('alice@example.com', $props['users']['data'][0]['email']);
    }

    public function test_detail_includes_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        Resume::factory()->for($user)->count(2)->create();
        Application::factory()->for($user)->count(3)->create();

        $response = $this->actingAs($admin)->get(route('admin.users.show', $user));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertSame(2, $props['user']['resume_count']);
        $this->assertSame(3, $props['user']['application_count']);
    }

    public function test_update_role_writes_audit_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'user']);

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $target), ['role' => 'admin'])
            ->assertRedirect();

        $this->assertSame('admin', $target->fresh()->role);
        $this->assertSame(1, AdminActivityLog::where('action', 'user.role_changed')->count());
    }

    public function test_deactivate_sets_column_and_audits(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'user']);

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $target), ['deactivate' => true])
            ->assertRedirect();

        $this->assertNotNull($target->fresh()->deactivated_at);
        $this->assertSame(1, AdminActivityLog::where('action', 'user.deactivated')->count());
    }

    public function test_non_admin_forbidden(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $target = User::factory()->create();

        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($user)->patch(route('admin.users.update', $target), ['role' => 'admin'])->assertForbidden();
    }
}
```

- [ ] **Step 3: Controller**

`app/Http/Controllers/Admin/UserController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends BaseController
{
    public function index(Request $request): Response
    {
        $query = User::query()->select('id', 'name', 'email', 'role', 'last_login_at', 'deactivated_at')
            ->withCount(['resumes', 'applications'])
            ->orderBy('email');

        if ($q = $request->string('q')->trim()->toString()) {
            $query->where(function ($w) use ($q) {
                $w->where('email', 'ilike', "%{$q}%")
                    ->orWhere('name', 'ilike', "%{$q}%");
            });
        }

        return Inertia::render('admin/users/Index', [
            'users' => $query->paginate(25)->withQueryString(),
            'filters' => ['q' => $q ?? ''],
        ]);
    }

    public function show(User $user): Response
    {
        $user->loadCount(['resumes', 'applications']);

        return Inertia::render('admin/users/Show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'last_login_at' => $user->last_login_at,
                'deactivated_at' => $user->deactivated_at,
                'resume_count' => $user->resumes_count,
                'application_count' => $user->applications_count,
            ],
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['sometimes', Rule::in(['user', 'admin'])],
            'deactivate' => ['sometimes', 'boolean'],
            'reactivate' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('role', $data) && $data['role'] !== $user->role) {
            $from = $user->role;
            $user->role = $data['role'];
            $user->save();
            $this->audit('user.role_changed', $user, ['from' => $from, 'to' => $data['role']]);
        }

        if (!empty($data['deactivate']) && !$user->deactivated_at) {
            $user->deactivated_at = now();
            $user->save();
            $this->audit('user.deactivated', $user);
        }

        if (!empty($data['reactivate']) && $user->deactivated_at) {
            $user->deactivated_at = null;
            $user->save();
            $this->audit('user.reactivated', $user);
        }

        return redirect()->back()->with('status', 'User updated.');
    }
}
```

- [ ] **Step 4: Routes**

Inside the admin group in `routes/web.php`:

```php
use App\Http\Controllers\Admin\UserController;

Route::get('users', [UserController::class, 'index'])->name('users.index');
Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
```

- [ ] **Step 5: Vue pages**

`resources/js/pages/admin/users/Index.vue`:

```vue
<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
  users: { data: Array<{ id: number; name: string; email: string; role: string; last_login_at: string | null; deactivated_at: string | null; resumes_count: number; applications_count: number }>; links: any[] };
  filters: { q: string };
}>();

const q = ref(props.filters.q);

function search() {
  router.get(route('admin.users.index'), { q: q.value }, { preserveState: true, replace: true });
}
</script>

<template>
  <Head title="Admin — Users" />
  <AdminLayout>
    <header class="mb-4 flex items-end justify-between">
      <h1 class="font-display text-3xl text-ink">Users</h1>
      <input v-model="q" @keyup.enter="search" placeholder="Search email or name" class="rounded border border-hairline bg-bone-2 px-3 py-2 text-sm" />
    </header>

    <table class="w-full border-collapse text-sm">
      <thead class="text-left text-ink-3">
        <tr>
          <th class="py-2 pr-3">Email</th>
          <th class="py-2 pr-3">Name</th>
          <th class="py-2 pr-3">Role</th>
          <th class="py-2 pr-3">Resumes</th>
          <th class="py-2 pr-3">Apps</th>
          <th class="py-2 pr-3">Last login</th>
          <th class="py-2 pr-3">Status</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-hairline">
        <tr v-for="user in users.data" :key="user.id">
          <td class="py-2 pr-3"><Link :href="route('admin.users.show', user.id)" class="text-ink hover:text-oxblood">{{ user.email }}</Link></td>
          <td class="py-2 pr-3 text-ink-2">{{ user.name }}</td>
          <td class="py-2 pr-3 text-ink-3">{{ user.role }}</td>
          <td class="py-2 pr-3 text-ink-3">{{ user.resumes_count }}</td>
          <td class="py-2 pr-3 text-ink-3">{{ user.applications_count }}</td>
          <td class="py-2 pr-3 text-ink-3">{{ user.last_login_at ? new Date(user.last_login_at).toLocaleDateString() : 'never' }}</td>
          <td class="py-2 pr-3"><span v-if="user.deactivated_at" class="text-oxblood">Deactivated</span><span v-else class="text-ink-3">Active</span></td>
        </tr>
      </tbody>
    </table>
  </AdminLayout>
</template>
```

`resources/js/pages/admin/users/Show.vue`:

```vue
<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps<{
  user: { id: number; name: string; email: string; role: string; last_login_at: string | null; deactivated_at: string | null; resume_count: number; application_count: number };
}>();

function toggleRole() {
  const nextRole = props.user.role === 'admin' ? 'user' : 'admin';
  if (window.confirm(`Change role to ${nextRole}?`)) {
    router.patch(route('admin.users.update', props.user.id), { role: nextRole });
  }
}

function deactivate() {
  if (window.confirm('Deactivate this user? They will no longer be able to log in.')) {
    router.patch(route('admin.users.update', props.user.id), { deactivate: true });
  }
}

function reactivate() {
  router.patch(route('admin.users.update', props.user.id), { reactivate: true });
}

function impersonate() {
  if (window.confirm(`Impersonate ${props.user.email}?`)) {
    router.post(route('admin.impersonate.start', props.user.id));
  }
}
</script>

<template>
  <Head :title="`Admin — ${user.email}`" />
  <AdminLayout>
    <Link :href="route('admin.users.index')" class="text-sm text-ink-3 hover:text-ink">&larr; All users</Link>
    <h1 class="mt-2 font-display text-3xl text-ink">{{ user.email }}</h1>
    <p class="text-ink-2">{{ user.name }} · {{ user.role }}</p>

    <dl class="mt-6 grid gap-3 sm:grid-cols-3">
      <div class="rounded border border-hairline bg-bone-2/40 px-4 py-3">
        <dt class="text-xs text-ink-3">Resumes</dt>
        <dd class="font-display text-xl text-ink">{{ user.resume_count }}</dd>
      </div>
      <div class="rounded border border-hairline bg-bone-2/40 px-4 py-3">
        <dt class="text-xs text-ink-3">Applications</dt>
        <dd class="font-display text-xl text-ink">{{ user.application_count }}</dd>
      </div>
      <div class="rounded border border-hairline bg-bone-2/40 px-4 py-3">
        <dt class="text-xs text-ink-3">Last login</dt>
        <dd class="font-display text-xl text-ink">{{ user.last_login_at ? new Date(user.last_login_at).toLocaleDateString() : 'never' }}</dd>
      </div>
    </dl>

    <div class="mt-8 flex flex-wrap gap-2">
      <button type="button" class="rounded border border-ink-3 px-3 py-1 text-sm" @click="toggleRole">
        Set role to {{ user.role === 'admin' ? 'user' : 'admin' }}
      </button>
      <button v-if="user.role !== 'admin'" type="button" class="rounded border border-oxblood px-3 py-1 text-sm text-oxblood" @click="impersonate">
        Impersonate
      </button>
      <button v-if="!user.deactivated_at" type="button" class="rounded border border-ink-3 px-3 py-1 text-sm text-ink-3" @click="deactivate">
        Deactivate
      </button>
      <button v-else type="button" class="rounded border border-ink-3 px-3 py-1 text-sm" @click="reactivate">
        Reactivate
      </button>
    </div>
  </AdminLayout>
</template>
```

- [ ] **Step 6: Run tests + full suite**

Expected: 5 new tests + 186 baseline = 191 pass.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_04_22_300100_add_deactivated_at_to_users.php app/Models/User.php app/Http/Controllers/Admin/UserController.php routes/web.php resources/js/pages/admin/users tests/Feature/Admin/AdminUserManagementTest.php
git commit -m "$(cat <<'EOF'
feat: admin Users list + detail + role edit + deactivate

Paginated user list with email/name search (ilike), detail view
with resume + application counts, role toggle (user ↔ admin),
deactivate + reactivate (new deactivated_at timestamp column on
users). All mutating actions write audit log entries via
$this->audit() inherited from Admin\\BaseController.

Impersonate button is visible on non-admin users; actual route
handler lands in Task 4.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Impersonation

**Files:**
- Create: `app/Http/Controllers/Admin/ImpersonationController.php`
- Create: `resources/js/components/admin/ImpersonationBanner.vue`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php` (share impersonation state)
- Modify: `resources/js/layouts/AppLayout.vue` (render banner when impersonating)
- Modify: `routes/web.php` (add impersonate start/stop routes)
- Create: `tests/Feature/Admin/ImpersonationTest.php`

- [ ] **Step 1: Tests**

`tests/Feature/Admin/ImpersonationTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_start_impersonation_of_non_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'user']);

        $this->actingAs($admin)
            ->post(route('admin.impersonate.start', $target))
            ->assertRedirect();

        $this->assertSame($target->id, auth()->id());
        $this->assertSame($admin->id, session('original_user_id'));
        $this->assertSame(1, AdminActivityLog::where('action', 'user.impersonation_started')->count());
    }

    public function test_impersonation_of_admin_is_forbidden(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('admin.impersonate.start', $otherAdmin))
            ->assertForbidden();

        $this->assertSame(0, AdminActivityLog::where('action', 'user.impersonation_started')->count());
    }

    public function test_nested_impersonation_is_forbidden(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $targetA = User::factory()->create(['role' => 'user']);
        $targetB = User::factory()->create(['role' => 'user']);

        $this->actingAs($admin)->post(route('admin.impersonate.start', $targetA));

        // We're now logged in as targetA. Try to start a nested impersonation.
        // The route is gated by EnsureAdmin which requires admin role — so targetA will get 403.
        $this->post(route('admin.impersonate.start', $targetB))->assertForbidden();
    }

    public function test_stop_returns_to_original_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'user']);

        $this->actingAs($admin)->post(route('admin.impersonate.start', $target));
        $this->post(route('admin.impersonate.stop'))->assertRedirect();

        $this->assertSame($admin->id, auth()->id());
        $this->assertNull(session('original_user_id'));
        $this->assertSame(1, AdminActivityLog::where('action', 'user.impersonation_stopped')->count());
    }
}
```

- [ ] **Step 2: Controller**

`app/Http/Controllers/Admin/ImpersonationController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Services\Admin\AdminLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends BaseController
{
    public function start(Request $request, User $user): RedirectResponse
    {
        abort_if($request->session()->has('original_user_id'), 403, 'Nested impersonation not allowed.');
        abort_if($user->role === 'admin', 403, 'Cannot impersonate another admin.');

        /** @var \App\Models\User $admin */
        $admin = $request->user();
        $request->session()->put('original_user_id', $admin->id);

        $this->audit('user.impersonation_started', $user, ['admin_id' => $admin->id]);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('original_user_id', $admin->id); // regenerate wipes; re-set

        return redirect('/dashboard');
    }
}
```

And a separate controller action for `stop` that does NOT extend the admin base (because during impersonation you're not an admin):

Add to `app/Http/Controllers/Admin/ImpersonationController.php` a class-level override or a sibling controller. Simplest: add a second method that uses `auth` middleware directly (not EnsureAdmin) via the route group.

Actually the clean design: put `stop` on a separate controller outside `Admin\*` namespace, since it shouldn't require admin role to execute.

`app/Http/Controllers/ImpersonationStopController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Admin\AdminLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationStopController extends Controller
{
    public function __invoke(Request $request, AdminLogger $logger): RedirectResponse
    {
        $originalId = $request->session()->get('original_user_id');
        abort_unless($originalId, 404, 'Not currently impersonating.');

        $admin = User::find($originalId);
        abort_unless($admin, 404);

        $impersonated = $request->user();
        $logger->record($admin, 'user.impersonation_stopped', $impersonated, [], $request->ip());

        Auth::login($admin);
        $request->session()->forget('original_user_id');
        $request->session()->regenerate();

        return redirect()->route('admin.users.show', $impersonated->id);
    }
}
```

- [ ] **Step 3: Routes**

Inside the admin group:

```php
use App\Http\Controllers\Admin\ImpersonationController;
Route::post('impersonate/{user}/start', [ImpersonationController::class, 'start'])->name('impersonate.start');
```

Outside the admin group but inside the `auth` middleware:

```php
use App\Http\Controllers\ImpersonationStopController;
Route::middleware('auth')->post('/impersonate/stop', ImpersonationStopController::class)->name('impersonate.stop');
```

- [ ] **Step 4: Inertia shared state**

Edit `app/Http/Middleware/HandleInertiaRequests.php`. In `share()`, add:

```php
'impersonation' => function () use ($request) {
    if (!$originalId = $request->session()->get('original_user_id')) {
        return null;
    }
    $current = $request->user();
    $original = \App\Models\User::find($originalId);
    return [
        'current_email' => $current?->email,
        'original_email' => $original?->email,
        'stop_url' => route('impersonate.stop'),
    ];
},
```

- [ ] **Step 5: Banner component**

`resources/js/components/admin/ImpersonationBanner.vue`:

```vue
<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const impersonation = computed(() => (page.props as any).impersonation);
</script>

<template>
  <div v-if="impersonation" class="sticky top-0 z-50 bg-oxblood px-4 py-2 text-center text-sm text-bone">
    Impersonating <strong>{{ impersonation.current_email }}</strong> (as {{ impersonation.original_email }}).
    <form :action="impersonation.stop_url" method="POST" class="ml-3 inline">
      <input type="hidden" name="_token" :value="(page.props as any).csrf_token || ''" />
      <button type="submit" class="underline hover:no-underline">Stop impersonating</button>
    </form>
  </div>
</template>
```

(Note: the `_token` approach uses Laravel's CSRF token if exposed; alternatively, use Inertia router's `router.post(impersonation.stop_url)`. Simpler via Inertia. Update to use `<button @click="router.post(impersonation.stop_url)">` if the CSRF token isn't shared as a prop.)

- [ ] **Step 6: Render banner in `AppLayout.vue`**

Edit `resources/js/layouts/AppLayout.vue`. At the very top of the rendered template, before the nav/main content, add:

```vue
<ImpersonationBanner />
```

And import it:

```ts
import ImpersonationBanner from '@/components/admin/ImpersonationBanner.vue';
```

- [ ] **Step 7: Run tests + full suite**

Expected: 4 new tests + 191 baseline = 195 pass.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Admin/ImpersonationController.php app/Http/Controllers/ImpersonationStopController.php app/Http/Middleware/HandleInertiaRequests.php resources/js/components/admin/ImpersonationBanner.vue resources/js/layouts/AppLayout.vue routes/web.php tests/Feature/Admin/ImpersonationTest.php
git commit -m "feat: admin impersonation — start / stop / nested-guard / persistent banner"
```

---

## Task 5: Admin Jobs browser (read-only)

**Files:**
- Create: `app/Http/Controllers/Admin/JobController.php`
- Create: `resources/js/pages/admin/jobs/Index.vue`
- Create: `resources/js/pages/admin/jobs/Show.vue`
- Modify: `routes/web.php` (add jobs routes)
- Create: `tests/Feature/Admin/AdminJobsBrowserTest.php`

- [ ] **Step 1: Tests**

`tests/Feature/Admin/AdminJobsBrowserTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\CanonicalJob;
use App\Models\JobMatch;
use App\Models\SearchProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminJobsBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_paginates_and_filters_by_source(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CanonicalJob::factory()->count(3)->create(['source' => 'greenhouse']);
        CanonicalJob::factory()->count(2)->create(['source' => 'lever']);

        $response = $this->actingAs($admin)->get(route('admin.jobs.index', ['source' => 'greenhouse']));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertCount(3, $props['jobs']['data']);
    }

    public function test_show_includes_matches_and_applications(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        $resume = \App\Models\Resume::factory()->for($user)->parsed()->create();
        $profile = SearchProfile::factory()->for($user)->create(['resume_id' => $resume->id]);
        $job = CanonicalJob::factory()->create();

        \DB::table('job_matches')->insert([
            'user_id' => $user->id,
            'search_profile_id' => $profile->id,
            'canonical_job_id' => $job->id,
            'stage1_score' => 0.8,
            'created_at' => now(),
        ]);
        Application::factory()->for($user)->create(['canonical_job_id' => $job->id]);

        $response = $this->actingAs($admin)->get(route('admin.jobs.show', $job));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertCount(1, $props['matches']);
        $this->assertCount(1, $props['applications']);
    }
}
```

- [ ] **Step 2: Controller**

`app/Http/Controllers/Admin/JobController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Models\Application;
use App\Models\CanonicalJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class JobController extends BaseController
{
    public function index(Request $request): Response
    {
        $query = CanonicalJob::query()
            ->orderByDesc('posted_at')
            ->select('id', 'source', 'source_board', 'company_name', 'title', 'location_raw', 'remote_mode', 'posted_at', 'closed_at');

        if ($source = $request->string('source')->toString()) {
            $query->where('source', $source);
        }
        if ($company = $request->string('company')->toString()) {
            $query->where('company_name', 'ilike', "%{$company}%");
        }
        if ($request->boolean('has_embedding')) {
            $query->whereNotNull('embedding');
        }
        if ($request->boolean('closed_only')) {
            $query->whereNotNull('closed_at');
        }

        return Inertia::render('admin/jobs/Index', [
            'jobs' => $query->paginate(50)->withQueryString(),
            'filters' => [
                'source' => $source ?? '',
                'company' => $company ?? '',
                'has_embedding' => $request->boolean('has_embedding'),
                'closed_only' => $request->boolean('closed_only'),
            ],
            'sourceTypes' => \App\Models\JobSource::distinct()->pluck('source_type')->toArray(),
        ]);
    }

    public function show(CanonicalJob $canonical_job): Response
    {
        $matches = DB::table('job_matches')
            ->join('users', 'job_matches.user_id', '=', 'users.id')
            ->where('job_matches.canonical_job_id', $canonical_job->id)
            ->select('job_matches.id', 'users.email as user_email', 'job_matches.stage1_score', 'job_matches.stage2_score', 'job_matches.created_at')
            ->orderByDesc('job_matches.stage1_score')
            ->get();

        $applications = Application::where('canonical_job_id', $canonical_job->id)
            ->with('user:id,email')
            ->get(['id', 'user_id', 'status', 'created_at']);

        return Inertia::render('admin/jobs/Show', [
            'job' => $canonical_job,
            'matches' => $matches,
            'applications' => $applications,
        ]);
    }
}
```

- [ ] **Step 3: Routes**

Inside admin group:

```php
use App\Http\Controllers\Admin\JobController;
Route::get('jobs', [JobController::class, 'index'])->name('jobs.index');
Route::get('jobs/{canonical_job}', [JobController::class, 'show'])->name('jobs.show');
```

- [ ] **Step 4: Vue pages**

`resources/js/pages/admin/jobs/Index.vue` — standard table with filter inputs (source dropdown, company text field, embedding + closed checkboxes). Each row links to show. Paginated with withQueryString links.

`resources/js/pages/admin/jobs/Show.vue` — displays job metadata + collapsible raw_payload JSON block + matches table (user email, score, date) + applications table (user email, status, date).

Both pages render inside `<AdminLayout>`. Keep styling consistent with Users pages.

- [ ] **Step 5: Run tests + full suite**

Expected: 2 new tests + 195 baseline = 197 pass.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Admin/JobController.php routes/web.php resources/js/pages/admin/jobs tests/Feature/Admin/AdminJobsBrowserTest.php
git commit -m "feat: admin Jobs browser — read-only list + detail with matches and applications"
```

---

## Task 6: Analytics service + page + Chart.js

**Files:**
- Create: `app/Services/Admin/Analytics.php`
- Create: `app/Http/Controllers/Admin/AnalyticsController.php`
- Create: `resources/js/pages/admin/Analytics.vue`
- Modify: `package.json` (install `chart.js@^4.4.0` + `vue-chartjs@^5.3.0`)
- Modify: `routes/web.php`
- Create: `tests/Feature/Admin/AnalyticsServiceTest.php`

- [ ] **Step 1: Install chart deps**

```bash
npm install --save chart.js@^4.4.0 vue-chartjs@^5.3.0
```

- [ ] **Step 2: Analytics service with 6 methods**

`app/Services/Admin/Analytics.php`:

```php
<?php

namespace App\Services\Admin;

use App\Models\Application;
use App\Models\JobSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class Analytics
{
    public function dailyIngestion(int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $rows = DB::table('canonical_jobs')
            ->selectRaw("DATE(created_at) as day, source, count(*) as total")
            ->where('created_at', '>=', $from)
            ->groupBy('day', 'source')
            ->orderBy('day')
            ->get();

        return $this->toTimeSeries($from, $days, $rows, 'day', 'source', 'total');
    }

    public function dailyMatches(int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $rows = DB::table('job_matches')
            ->selectRaw("DATE(created_at) as day, count(*) as total")
            ->where('created_at', '>=', $from)
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return $this->toSingleSeries($from, $days, $rows, 'day', 'total', 'Matches');
    }

    public function dailyRerankCalls(int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $rows = DB::table('ai_usage_events')
            ->selectRaw("DATE(created_at) as day, count(*) as total")
            ->where('operation', 'rerank')
            ->where('created_at', '>=', $from)
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return $this->toSingleSeries($from, $days, $rows, 'day', 'total', 'Rerank calls');
    }

    public function dailyAiCost(int $days = 30, ?int $userId = null): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $query = DB::table('ai_usage_events')
            ->selectRaw("DATE(created_at) as day, sum(estimated_cost_usd) as total")
            ->where('created_at', '>=', $from);

        if ($userId) {
            $query->where('user_id', $userId);
        }

        $rows = $query->groupBy('day')->orderBy('day')->get();

        return $this->toSingleSeries($from, $days, $rows, 'day', 'total', 'AI spend (USD)', fn ($v) => (float) $v);
    }

    public function applicationFunnel(): array
    {
        return Application::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();
    }

    public function sourceHealth(): array
    {
        return JobSource::query()
            ->select('id', 'source_type', 'identifier', 'display_name', 'is_enabled', 'last_fetched_at', 'last_error', 'consecutive_failures')
            ->orderBy('source_type')
            ->get()
            ->toArray();
    }

    protected function toTimeSeries(Carbon $from, int $days, $rows, string $dayCol, string $groupCol, string $valueCol): array
    {
        $labels = [];
        for ($i = 0; $i < $days; $i++) {
            $labels[] = $from->copy()->addDays($i)->toDateString();
        }

        $groups = [];
        foreach ($rows as $row) {
            $groups[$row->{$groupCol}] = $groups[$row->{$groupCol}] ?? array_fill(0, $days, 0);
            $dayIdx = array_search((string) $row->{$dayCol}, $labels, true);
            if ($dayIdx !== false) {
                $groups[$row->{$groupCol}][$dayIdx] = (int) $row->{$valueCol};
            }
        }

        $datasets = [];
        foreach ($groups as $group => $data) {
            $datasets[] = ['label' => $group, 'data' => $data];
        }

        return ['labels' => $labels, 'datasets' => $datasets];
    }

    protected function toSingleSeries(Carbon $from, int $days, $rows, string $dayCol, string $valueCol, string $label, ?callable $cast = null): array
    {
        $labels = [];
        $data = array_fill(0, $days, 0);
        for ($i = 0; $i < $days; $i++) {
            $labels[] = $from->copy()->addDays($i)->toDateString();
        }
        foreach ($rows as $row) {
            $dayIdx = array_search((string) $row->{$dayCol}, $labels, true);
            if ($dayIdx !== false) {
                $data[$dayIdx] = $cast ? $cast($row->{$valueCol}) : (int) $row->{$valueCol};
            }
        }
        return ['labels' => $labels, 'datasets' => [['label' => $label, 'data' => $data]]];
    }
}
```

- [ ] **Step 3: Tests**

`tests/Feature/Admin/AnalyticsServiceTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\CanonicalJob;
use App\Models\User;
use App\Services\Admin\Analytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_ingestion_returns_labels_and_grouped_datasets(): void
    {
        CanonicalJob::factory()->count(2)->create(['source' => 'greenhouse', 'created_at' => now()]);
        CanonicalJob::factory()->create(['source' => 'lever', 'created_at' => now()->subDay()]);

        $result = app(Analytics::class)->dailyIngestion(7);

        $this->assertCount(7, $result['labels']);
        $this->assertNotEmpty($result['datasets']);
    }

    public function test_application_funnel_counts_by_status(): void
    {
        $user = User::factory()->create();
        Application::factory()->for($user)->count(3)->create(['status' => 'saved']);
        Application::factory()->for($user)->count(2)->create(['status' => 'applied']);

        $funnel = app(Analytics::class)->applicationFunnel();
        $this->assertSame(3, $funnel['saved']);
        $this->assertSame(2, $funnel['applied']);
    }

    public function test_daily_ai_cost_filters_by_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        DB::table('ai_usage_events')->insert([
            ['user_id' => $user->id, 'provider' => 'openai', 'model' => 'x', 'operation' => 'embed', 'input_tokens' => 0, 'output_tokens' => 0, 'estimated_cost_usd' => 0.5, 'created_at' => now()],
            ['user_id' => $other->id, 'provider' => 'openai', 'model' => 'x', 'operation' => 'embed', 'input_tokens' => 0, 'output_tokens' => 0, 'estimated_cost_usd' => 1.0, 'created_at' => now()],
        ]);

        $global = app(Analytics::class)->dailyAiCost(7, null);
        $userOnly = app(Analytics::class)->dailyAiCost(7, $user->id);

        $this->assertGreaterThan($userOnly['datasets'][0]['data'][6], 0);
        $this->assertEquals(0.5, $userOnly['datasets'][0]['data'][6]);
        $this->assertEquals(1.5, $global['datasets'][0]['data'][6]);
    }
}
```

- [ ] **Step 4: Controller**

`app/Http/Controllers/Admin/AnalyticsController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\Analytics;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends BaseController
{
    public function show(Analytics $analytics): Response
    {
        return Inertia::render('admin/Analytics', [
            'ingestion' => $analytics->dailyIngestion(30),
            'matches' => $analytics->dailyMatches(30),
            'rerankCalls' => $analytics->dailyRerankCalls(30),
            'aiCost' => $analytics->dailyAiCost(30),
            'funnel' => $analytics->applicationFunnel(),
        ]);
    }
}
```

- [ ] **Step 5: Vue page with 5 Chart.js charts**

`resources/js/pages/admin/Analytics.vue`:

```vue
<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import { Line, Bar } from 'vue-chartjs';
import { Chart as ChartJS, CategoryScale, LinearScale, PointElement, LineElement, BarElement, Title, Tooltip, Legend } from 'chart.js';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, Title, Tooltip, Legend);

defineProps<{
  ingestion: { labels: string[]; datasets: Array<{ label: string; data: number[] }> };
  matches: { labels: string[]; datasets: Array<{ label: string; data: number[] }> };
  rerankCalls: { labels: string[]; datasets: Array<{ label: string; data: number[] }> };
  aiCost: { labels: string[]; datasets: Array<{ label: string; data: number[] }> };
  funnel: Record<string, number>;
}>();

const funnelData = (funnel: Record<string, number>) => ({
  labels: Object.keys(funnel),
  datasets: [{ label: 'Applications', data: Object.values(funnel) }],
});

const baseOptions = { responsive: true, maintainAspectRatio: false };
</script>

<template>
  <Head title="Admin — Analytics" />
  <AdminLayout>
    <h1 class="font-display text-3xl text-ink">Analytics</h1>

    <div class="mt-6 grid gap-6 md:grid-cols-2">
      <section class="rounded border border-hairline bg-bone-2/40 p-4">
        <h2 class="mb-2 text-sm font-medium text-ink-2">Daily ingestion (30d)</h2>
        <div style="height:220px"><Line :data="ingestion" :options="baseOptions" /></div>
      </section>
      <section class="rounded border border-hairline bg-bone-2/40 p-4">
        <h2 class="mb-2 text-sm font-medium text-ink-2">Daily matches (30d)</h2>
        <div style="height:220px"><Line :data="matches" :options="baseOptions" /></div>
      </section>
      <section class="rounded border border-hairline bg-bone-2/40 p-4">
        <h2 class="mb-2 text-sm font-medium text-ink-2">Rerank calls (30d)</h2>
        <div style="height:220px"><Line :data="rerankCalls" :options="baseOptions" /></div>
      </section>
      <section class="rounded border border-hairline bg-bone-2/40 p-4">
        <h2 class="mb-2 text-sm font-medium text-ink-2">AI spend (30d, USD)</h2>
        <div style="height:220px"><Line :data="aiCost" :options="baseOptions" /></div>
      </section>
      <section class="md:col-span-2 rounded border border-hairline bg-bone-2/40 p-4">
        <h2 class="mb-2 text-sm font-medium text-ink-2">Application funnel</h2>
        <div style="height:220px"><Bar :data="funnelData(funnel)" :options="baseOptions" /></div>
      </section>
    </div>
  </AdminLayout>
</template>
```

- [ ] **Step 6: Route**

Inside admin group:

```php
use App\Http\Controllers\Admin\AnalyticsController;
Route::get('analytics', [AnalyticsController::class, 'show'])->name('analytics');
```

- [ ] **Step 7: Run tests + full suite + build check**

Expected: 3 new tests + 197 baseline = 200 pass. `npm run build` must succeed.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add package.json package-lock.json app/Services/Admin/Analytics.php app/Http/Controllers/Admin/AnalyticsController.php resources/js/pages/admin/Analytics.vue routes/web.php tests/Feature/Admin/AnalyticsServiceTest.php
git commit -m "feat: admin Analytics page with 5 Chart.js charts"
```

---

## Task 7: Admin Settings editor

**Files:**
- Create: `app/Http/Controllers/Admin/SettingController.php`
- Create: `resources/js/pages/admin/Settings.vue`
- Modify: `routes/web.php`
- Create: `tests/Feature/Admin/AdminSettingsTest.php`

- [ ] **Step 1: Tests**

`tests/Feature/Admin/AdminSettingsTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\AdminActivityLog;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_all_site_settings_and_env_secrets_masked(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        config(['services.openai.api_key' => 'sk-test-12345']);

        $response = $this->actingAs($admin)->get(route('admin.settings.index'));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertNotEmpty($props['settings']);
        $this->assertNotEmpty($props['secrets']);
        $secret = collect($props['secrets'])->firstWhere('key', 'OPENAI_API_KEY');
        $this->assertStringContainsString('…', $secret['masked']);
    }

    public function test_update_writes_value_and_audit_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        SiteSetting::create(['key' => 'rerank_enabled', 'value' => 'true', 'type' => 'bool', 'description' => '']);

        $this->actingAs($admin)
            ->patch(route('admin.settings.update', 'rerank_enabled'), ['value' => 'false'])
            ->assertRedirect();

        $this->assertSame('false', SiteSetting::where('key', 'rerank_enabled')->value('value'));
        $this->assertSame(1, AdminActivityLog::where('action', 'site_setting.updated')->count());
    }

    public function test_update_rejects_non_existent_key(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->patch(route('admin.settings.update', 'nonexistent'), ['value' => 'x'])
            ->assertStatus(404);
    }

    public function test_int_type_validation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        SiteSetting::create(['key' => 'rerank_shortlist_size', 'value' => '20', 'type' => 'int', 'description' => '']);

        $this->actingAs($admin)
            ->patch(route('admin.settings.update', 'rerank_shortlist_size'), ['value' => 'not-a-number'])
            ->assertSessionHasErrors('value');
    }
}
```

- [ ] **Step 2: Controller**

`app/Http/Controllers/Admin/SettingController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Models\SiteSetting;
use App\Services\SiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends BaseController
{
    protected array $envSecretKeys = [
        'OPENAI_API_KEY' => 'services.openai.api_key',
        'ANTHROPIC_API_KEY' => 'services.anthropic.api_key',
        'ADZUNA_APP_ID' => 'services.adzuna.app_id',
        'ADZUNA_APP_KEY' => 'services.adzuna.app_key',
        'USAJOBS_AUTH_KEY' => 'services.usajobs.auth_key',
    ];

    public function index(): Response
    {
        $settings = SiteSetting::orderBy('key')->get(['key', 'value', 'type', 'description']);

        $secrets = [];
        foreach ($this->envSecretKeys as $label => $configKey) {
            $value = (string) config($configKey, '');
            $secrets[] = [
                'key' => $label,
                'masked' => $this->mask($value),
                'is_set' => $value !== '',
            ];
        }

        return Inertia::render('admin/Settings', [
            'settings' => $settings,
            'secrets' => $secrets,
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $setting = SiteSetting::where('key', $key)->first();
        abort_unless($setting, 404);

        $rules = match ($setting->type) {
            'int' => ['required', 'integer'],
            'bool' => ['required', 'in:true,false'],
            'json' => ['required', 'json'],
            default => ['required', 'string'],
        };
        $data = $request->validate(['value' => $rules]);

        $old = $setting->value;
        $setting->value = (string) $data['value'];
        $setting->save();

        app(SiteSettings::class)->invalidate();

        $this->audit('site_setting.updated', $setting, ['key' => $key, 'old' => $old, 'new' => $setting->value]);

        return redirect()->back()->with('status', 'Setting updated.');
    }

    protected function mask(string $value): string
    {
        if ($value === '') {
            return '(not set)';
        }
        if (strlen($value) <= 8) {
            return str_repeat('•', strlen($value));
        }
        return substr($value, 0, 4) . '…' . substr($value, -4);
    }
}
```

Note: `SiteSettings::invalidate()` — if the service doesn't already have this method from Plan 1, add one that clears the Redis cache key. Read the existing service to confirm.

- [ ] **Step 3: Routes**

Inside admin group:

```php
use App\Http\Controllers\Admin\SettingController;
Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
Route::patch('settings/{key}', [SettingController::class, 'update'])->name('settings.update');
```

- [ ] **Step 4: Vue page**

`resources/js/pages/admin/Settings.vue` — renders settings in a simple 2-column form (key + value input). Each row has an inline save button. Env secrets render as a separate "Environment-sourced secrets (read-only)" section with the masked value and an "is set"/"(not set)" indicator. Group settings by an implicit prefix (e.g., `rerank_*` as "Matching", `openai_*` as "AI", etc.) — optional, can skip for v1.

- [ ] **Step 5: Run tests + full suite**

Expected: 4 new tests + 200 baseline = 204 pass.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Admin/SettingController.php app/Services/SiteSettings.php resources/js/pages/admin/Settings.vue routes/web.php tests/Feature/Admin/AdminSettingsTest.php
git commit -m "feat: admin Settings editor with type-validated updates and masked env secrets"
```

---

## Task 8: Admin Audit log viewer

**Files:**
- Create: `app/Http/Controllers/Admin/AuditLogController.php`
- Create: `resources/js/pages/admin/Audit.vue`
- Modify: `routes/web.php`
- Create: `tests/Feature/Admin/AdminAuditLogViewerTest.php`

- [ ] **Step 1: Controller**

`app/Http/Controllers/Admin/AuditLogController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Models\AdminActivityLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends BaseController
{
    public function index(Request $request): Response
    {
        $query = AdminActivityLog::query()
            ->with('admin:id,email')
            ->orderByDesc('created_at');

        if ($adminId = $request->integer('admin_id')) {
            $query->where('admin_user_id', $adminId);
        }
        if ($action = $request->string('action')->toString()) {
            $query->where('action', 'ilike', "%{$action}%");
        }
        if ($from = $request->string('from')->toString()) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->string('to')->toString()) {
            $query->where('created_at', '<=', $to);
        }

        return Inertia::render('admin/Audit', [
            'rows' => $query->paginate(50)->withQueryString(),
            'filters' => [
                'admin_id' => $adminId ?? '',
                'action' => $action ?? '',
                'from' => $from ?? '',
                'to' => $to ?? '',
            ],
        ]);
    }
}
```

- [ ] **Step 2: Route**

```php
use App\Http\Controllers\Admin\AuditLogController;
Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
```

- [ ] **Step 3: Tests**

`tests/Feature/Admin/AdminAuditLogViewerTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuditLogViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_paginates_and_filters_by_action(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        AdminActivityLog::factory()->count(3)->create(['action' => 'user.role_changed', 'admin_user_id' => $admin->id]);
        AdminActivityLog::factory()->count(2)->create(['action' => 'user.deactivated', 'admin_user_id' => $admin->id]);

        $response = $this->actingAs($admin)->get(route('admin.audit.index', ['action' => 'role_changed']));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertCount(3, $props['rows']['data']);
    }

    public function test_non_admin_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']))->get(route('admin.audit.index'))->assertForbidden();
    }
}
```

- [ ] **Step 4: Vue page**

Standard table: admin (email), action, target_type + id, ip, payload (JSON snippet truncated), created_at. Filter inputs at top (action text field, date range from/to).

- [ ] **Step 5: Run tests + full suite**

Expected: 2 new + 204 baseline = 206 pass.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Admin/AuditLogController.php resources/js/pages/admin/Audit.vue routes/web.php tests/Feature/Admin/AdminAuditLogViewerTest.php
git commit -m "feat: admin Audit log viewer with action + date-range filters"
```

---

## Task 9: Admin Error log + retry actions

**Files:**
- Create: `app/Services/Admin/ErrorLog.php`
- Create: `app/Http/Controllers/Admin/ErrorLogController.php`
- Create: `resources/js/pages/admin/Errors.vue`
- Modify: `routes/web.php`
- Create: `tests/Feature/Admin/AdminErrorLogTest.php`

- [ ] **Step 1: `ErrorLog` service**

`app/Services/Admin/ErrorLog.php`:

```php
<?php

namespace App\Services\Admin;

use App\Models\CanonicalJob;
use App\Models\JobMatch;
use App\Models\Resume;
use Illuminate\Support\Facades\DB;

class ErrorLog
{
    public function failedParses(int $limit = 50): array
    {
        return Resume::where('parse_status', 'parse_failed')
            ->with('user:id,email')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public function stuckResumeEmbeddings(int $limit = 50): array
    {
        return DB::table('resumes')
            ->where('parse_status', 'parsed')
            ->whereNull('embedding')
            ->where('updated_at', '<', now()->subHour())
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public function stuckJobEmbeddings(int $limit = 50): array
    {
        return DB::table('canonical_jobs')
            ->whereNull('embedding')
            ->whereNull('closed_at')
            ->where('created_at', '<', now()->subHour())
            ->limit($limit)
            ->get(['id', 'source', 'source_id', 'company_name', 'title', 'created_at'])
            ->toArray();
    }

    public function rerankUnavailable(int $limit = 50): array
    {
        return DB::table('job_matches')
            ->where('stage2_rationale', 'rerank unavailable')
            ->where('stage2_at', '<', now()->subHours(6))
            ->limit($limit)
            ->get(['id', 'user_id', 'search_profile_id', 'canonical_job_id', 'stage2_at'])
            ->toArray();
    }
}
```

- [ ] **Step 2: Controller**

`app/Http/Controllers/Admin/ErrorLogController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Jobs\EmbedJob;
use App\Jobs\EmbedResume;
use App\Jobs\ParseResume;
use App\Jobs\RerankShortlist;
use App\Models\CanonicalJob;
use App\Models\JobMatch;
use App\Models\Resume;
use App\Services\Admin\ErrorLog;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ErrorLogController extends BaseController
{
    public function show(ErrorLog $log): Response
    {
        return Inertia::render('admin/Errors', [
            'failedParses' => $log->failedParses(),
            'stuckResumeEmbeddings' => $log->stuckResumeEmbeddings(),
            'stuckJobEmbeddings' => $log->stuckJobEmbeddings(),
            'rerankUnavailable' => $log->rerankUnavailable(),
        ]);
    }

    public function retryResumeParse(Resume $resume): RedirectResponse
    {
        $resume->update(['parse_status' => 'pending']);
        ParseResume::dispatch($resume->id);
        $this->audit('resume.parse_retried', $resume);
        return redirect()->back();
    }

    public function retryResumeEmbed(Resume $resume): RedirectResponse
    {
        EmbedResume::dispatch($resume->id);
        $this->audit('resume.embed_retried', $resume);
        return redirect()->back();
    }

    public function retryJobEmbed(CanonicalJob $canonical_job): RedirectResponse
    {
        EmbedJob::dispatch($canonical_job->id);
        $this->audit('job.embed_retried', $canonical_job);
        return redirect()->back();
    }

    public function retryRerank(JobMatch $match): RedirectResponse
    {
        RerankShortlist::dispatch($match->search_profile_id, 20, null);
        $this->audit('match.rerank_retried', $match);
        return redirect()->back();
    }
}
```

- [ ] **Step 3: Routes**

```php
use App\Http\Controllers\Admin\ErrorLogController;
Route::get('errors', [ErrorLogController::class, 'show'])->name('errors');
Route::post('errors/resumes/{resume}/retry-parse', [ErrorLogController::class, 'retryResumeParse'])->name('errors.resume.retry-parse');
Route::post('errors/resumes/{resume}/retry-embed', [ErrorLogController::class, 'retryResumeEmbed'])->name('errors.resume.retry-embed');
Route::post('errors/jobs/{canonical_job}/retry-embed', [ErrorLogController::class, 'retryJobEmbed'])->name('errors.job.retry-embed');
Route::post('errors/matches/{match}/retry-rerank', [ErrorLogController::class, 'retryRerank'])->name('errors.match.retry-rerank');
```

- [ ] **Step 4: Vue page**

`resources/js/pages/admin/Errors.vue` — four sections (failed parses, stuck resume embeddings, stuck job embeddings, rerank-unavailable matches), each with a retry button per row. Buttons use `router.post` with `preserveScroll`.

- [ ] **Step 5: Tests**

`tests/Feature/Admin/AdminErrorLogTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Jobs\EmbedJob;
use App\Jobs\EmbedResume;
use App\Jobs\ParseResume;
use App\Jobs\RerankShortlist;
use App\Models\AdminActivityLog;
use App\Models\CanonicalJob;
use App\Models\JobMatch;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminErrorLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_failed_parses_and_stuck_embeddings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        Resume::factory()->for($user)->create(['parse_status' => 'parse_failed']);

        $response = $this->actingAs($admin)->get(route('admin.errors'));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertCount(1, $props['failedParses']);
    }

    public function test_retry_resume_parse_dispatches_and_audits(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create(['parse_status' => 'parse_failed']);

        $this->actingAs($admin)
            ->post(route('admin.errors.resume.retry-parse', $resume))
            ->assertRedirect();

        Queue::assertPushed(ParseResume::class);
        $this->assertSame('pending', $resume->fresh()->parse_status);
        $this->assertSame(1, AdminActivityLog::where('action', 'resume.parse_retried')->count());
    }

    public function test_retry_job_embed_dispatches(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $job = CanonicalJob::factory()->create();

        $this->actingAs($admin)->post(route('admin.errors.job.retry-embed', $job))->assertRedirect();

        Queue::assertPushed(EmbedJob::class);
        $this->assertSame(1, AdminActivityLog::where('action', 'job.embed_retried')->count());
    }
}
```

- [ ] **Step 6: Run tests + full suite**

Expected: 3 new + 206 baseline = 209 pass.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Admin/ErrorLog.php app/Http/Controllers/Admin/ErrorLogController.php resources/js/pages/admin/Errors.vue routes/web.php tests/Feature/Admin/AdminErrorLogTest.php
git commit -m "feat: admin Error log with retry actions for parse/embed/rerank"
```

---

## Task 10: Horizon mount + E2E + docs + final verification

**Files:**
- Modify: `routes/web.php` (Horizon gate)
- Modify: `config/horizon.php` (if needed for the custom auth gate)
- Modify: `app/Providers/HorizonServiceProvider.php` (Gate::define for viewing)
- Modify: `tests/e2e/feed-happy-path.spec.ts` (admin flow extension)
- Modify: `CLAUDE.md` (Plan 5 architecture bullets)
- Modify: `README.md` (Plan 5 features)

- [ ] **Step 1: Gate Horizon**

Edit `app/Providers/HorizonServiceProvider.php` — replace the `gate()` method body:

```php
protected function gate(): void
{
    Gate::define('viewHorizon', function ($user) {
        return $user && $user->isAdmin();
    });
}
```

This ensures Horizon's built-in Gate::authorize('viewHorizon') blocks non-admins.

- [ ] **Step 2: Test that Horizon route is gated**

Add to a new `tests/Feature/Admin/HorizonGateTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HorizonGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_horizon(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/horizon')->assertSuccessful();
    }

    public function test_non_admin_cannot_view_horizon(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $response = $this->actingAs($user)->get('/horizon');
        $this->assertTrue(in_array($response->status(), [403, 404]), "Non-admin got status {$response->status()}");
    }
}
```

- [ ] **Step 3: E2E admin smoke**

Extend `tests/e2e/feed-happy-path.spec.ts`. After the save-to-kanban assertions, add (note: our E2E test user has `role='user'`, so we need to promote it or register a second admin. Simplest: make the E2E user admin via a test-only route):

Add to `routes/testing.php`:

```php
Route::post('/testing/promote-to-admin/{user}', function ($userId) {
    \App\Models\User::findOrFail($userId)->update(['role' => 'admin']);
    return response()->json(['ok' => true]);
});
```

In the Playwright spec, before the admin assertions:

```ts
// Promote the E2E test user to admin
const userEmail = email; // captured earlier
const users = await request.get('/api/testing-user-id'); // or use the Show page to grab id
// simpler: use a promote-by-email helper:
await request.post('/testing/promote-by-email', { data: { email: userEmail } });
await page.goto('/dashboard');
await page.reload(); // re-read auth user with new role
await page.goto('/admin');
await expect(page.getByText('Overview')).toBeVisible();
```

Implement the helper in `routes/testing.php`:

```php
Route::post('/testing/promote-by-email', function (\Illuminate\Http\Request $request) {
    $email = $request->input('email');
    \App\Models\User::where('email', $email)->update(['role' => 'admin']);
    return response()->json(['ok' => true]);
});
```

- [ ] **Step 4: CLAUDE.md updates**

Append under "Architecture notes":

```markdown
- **Admin dashboard** — `/admin` renders inside `AdminLayout`. All admin controllers extend `App\Http\Controllers\Admin\BaseController` which applies `EnsureAdmin` middleware and exposes `$this->audit()` for writing `admin_activity_log` rows. `admin_activity_log` is append-only (boot-time updating listener throws LogicException).
- **Impersonation** is one-level only. `original_user_id` lives in the session; `ImpersonationBanner` renders whenever the key is set. `POST /impersonate/stop` takes the user back without requiring admin role (it's the impersonated user that clicks it).
- **Analytics** — `App\Services\Admin\Analytics` returns Chart.js-ready arrays. No daily-aggregates table in v1; raw queries are fast enough for the seeded dataset.
- **Horizon** mounted at `/horizon` gated by the `viewHorizon` Gate which checks `user->isAdmin()`.
```

Append under "Key commands":

```markdown
- Horizon is gated by the Gate::define('viewHorizon') registered in HorizonServiceProvider — admins only.
```

Update "What's here now" section to reflect Plan 5.

- [ ] **Step 5: README.md updates**

Under "What's here now", add:
- Admin dashboard (Overview / Users / Jobs / Analytics / Settings / Audit / Errors)
- Impersonation (one-level) with audit trail
- AI cost analytics joining `ai_usage_events.context_id` to profile id to user
- Horizon mount for queue monitoring

- [ ] **Step 6: Final verification**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --compact
```

Expected: ~211 tests pass (209 + 2 Horizon gate).

```bash
npm run build
npm run lint
```

Both clean.

```bash
npm run test:e2e
```

Expected: Playwright spec passes including the admin smoke flow.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/Providers/HorizonServiceProvider.php tests/Feature/Admin/HorizonGateTest.php tests/e2e/feed-happy-path.spec.ts routes/testing.php CLAUDE.md README.md
git commit -m "$(cat <<'EOF'
feat: Horizon gate + E2E admin smoke + Plan 5 docs

Horizon's Gate::define('viewHorizon') now checks user->isAdmin().
Non-admins get 403 on /horizon; feature test covers both cases.

Playwright spec promotes the E2E test user to admin via a test-only
route and confirms /admin/Overview renders.

CLAUDE.md gains 4 Plan 5 architecture bullets (admin dashboard,
impersonation, Analytics service, Horizon gate). README's "What's
here now" reflects Plan 5 surfaces.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Done

Plan 5 is complete when all 10 tasks are green, the PHP suite reports ~211 tests passing, and the Playwright spec includes the admin smoke. Plan 6 closes the 6-plan arc with Reverb real-time, pgvector ivfflat retuning, brand-string leak guard, settings-driven theming, and email notifications.
