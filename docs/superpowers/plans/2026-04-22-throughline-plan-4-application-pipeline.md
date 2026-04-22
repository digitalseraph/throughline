# Throughline — Plan 4: Application pipeline + remaining drivers

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the Plan 3 feed's "I care about this job" moment into a durable, auditable application record. Users save from feed, track through 8 statuses via kanban drag-and-drop, attach contacts + documents, and receive reminder nudges. Remotive + USAJobs drivers complete the starter ingestion set.

**Architecture:** Four new tables (`applications` + append-only `application_events` + `application_contacts` + `application_documents`) with `(user_id, canonical_job_id)` uniqueness. `EventWriter` service is the single writer of `application_events` rows — the `ApplicationEvent` model enforces append-only at the boot level. Kanban is `vuedraggable` (new npm dep); each drop is a single PATCH. `stage2_snapshot` jsonb preserves rerank context at save-time so later reranks can't retcon user history.

**Tech Stack:** No new composer packages. New npm: `vuedraggable@^4.1.0`. Same Laravel 12 + PHP 8.4 + Postgres 16 + pgvector + Redis + Claude + OpenAI stack.

**Spec reference:** [`docs/superpowers/specs/2026-04-22-throughline-plan-4-design.md`](../specs/2026-04-22-throughline-plan-4-design.md)

---

## Preconditions

- Plan 3 merged (PR #16). Branch `plan-4-application-pipeline` forked from main at the Plan-3 merge commit.
- 134 PHP tests passing baseline.
- Docker stack running; `throughline_test` DB intact.

---

## Task index

1. Four application-pipeline migrations + models + factories + relations + 2 new site_settings keys + 2 seed rows
2. `EventWriter` service + `ApplicationEvent` append-only boot guard + tests
3. Save-from-feed controller + feed card "Save" affordance + tests
4. Application list (kanban) + detail controllers + base UI (no drag yet)
5. Kanban drag-and-drop + status transition PATCH + `vuedraggable` integration + tests
6. Contacts CRUD controller + UI + tests
7. Documents upload controller + UI + tests
8. Reminder scan scheduled job + nav badge + `throughline:reminder-scan-now` command + tests
9. `RemotiveDriver` + fixtures + tests
10. `USAJobsDriver` (paginated + authenticated) + fixtures + tests
11. E2E spec extension + CLAUDE.md / README + final verification

---

## Task 1: Schema + models + seeds

**Files:**
- Create: `database/migrations/2026_04_22_200000_create_applications_table.php`
- Create: `database/migrations/2026_04_22_200100_create_application_events_table.php`
- Create: `database/migrations/2026_04_22_200200_create_application_contacts_table.php`
- Create: `database/migrations/2026_04_22_200300_create_application_documents_table.php`
- Create: `app/Models/Application.php`
- Create: `app/Models/ApplicationEvent.php`
- Create: `app/Models/ApplicationContact.php`
- Create: `app/Models/ApplicationDocument.php`
- Create: `database/factories/ApplicationFactory.php`
- Create: `database/factories/ApplicationEventFactory.php`
- Create: `database/factories/ApplicationContactFactory.php`
- Create: `database/factories/ApplicationDocumentFactory.php`
- Modify: `app/Models/User.php` (add `applications()` HasMany)
- Modify: `database/seeders/SiteSettingsSeeder.php` (append 2 keys)
- Modify: `database/seeders/JobSourcesSeeder.php` (append 2 rows — remotive, usajobs)

- [ ] **Step 1: `create_applications_table`**

`database/migrations/2026_04_22_200000_create_applications_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('canonical_job_id')->constrained('canonical_jobs')->cascadeOnDelete();
            $table->foreignId('search_profile_id')->nullable()->constrained('search_profiles')->nullOnDelete();
            $table->foreignId('resume_id')->nullable()->constrained('resumes')->nullOnDelete();
            $table->string('status', 24)->default('saved');
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('next_action_at')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('stage2_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'canonical_job_id']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'next_action_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
```

- [ ] **Step 2: `create_application_events_table`**

`database/migrations/2026_04_22_200100_create_application_events_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('application_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->jsonb('payload')->default('{}');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['application_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_events');
    }
};
```

- [ ] **Step 3: `create_application_contacts_table`**

`database/migrations/2026_04_22_200200_create_application_contacts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('application_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('role', 24)->default('other');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('linkedin_url')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_contacts');
    }
};
```

- [ ] **Step 4: `create_application_documents_table`**

`database/migrations/2026_04_22_200300_create_application_documents_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('application_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24)->default('other');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime', 64)->nullable();
            $table->integer('size_bytes')->default(0);
            $table->timestamp('uploaded_at')->useCurrent();

            $table->index('application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_documents');
    }
};
```

- [ ] **Step 5: Models**

`app/Models/Application.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    use HasFactory;

    public const STATUSES = ['saved', 'applied', 'phone_screen', 'technical', 'onsite', 'offer', 'rejected', 'withdrawn'];
    public const TERMINAL_STATUSES = ['offer', 'rejected', 'withdrawn'];

    protected $fillable = [
        'canonical_job_id',
        'search_profile_id',
        'resume_id',
        'status',
        'applied_at',
        'next_action_at',
        'notes',
        'stage2_snapshot',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
        'next_action_at' => 'datetime',
        'stage2_snapshot' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canonicalJob(): BelongsTo
    {
        return $this->belongsTo(CanonicalJob::class);
    }

    public function resume(): BelongsTo
    {
        return $this->belongsTo(Resume::class);
    }

    public function searchProfile(): BelongsTo
    {
        return $this->belongsTo(SearchProfile::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApplicationEvent::class)->orderBy('occurred_at');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ApplicationContact::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES);
    }
}
```

`app/Models/ApplicationEvent.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ApplicationEvent extends Model
{
    use HasFactory;

    public const TYPES = ['status_change', 'note', 'interview', 'reminder', 'contact_added', 'document_attached'];

    protected $fillable = [
        'application_id',
        'type',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Append-only invariant — updates at the Eloquent layer are rejected.
        // Corrections are done by writing compensating events instead.
        static::updating(function (ApplicationEvent $event) {
            throw new LogicException('application_events rows are append-only. Write a compensating event instead.');
        });
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
```

`app/Models/ApplicationContact.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationContact extends Model
{
    use HasFactory;

    public const ROLES = ['recruiter', 'hiring_manager', 'interviewer', 'referral', 'other'];

    protected $fillable = ['application_id', 'name', 'role', 'email', 'phone', 'linkedin_url', 'notes'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
```

`app/Models/ApplicationDocument.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationDocument extends Model
{
    use HasFactory;

    public const KINDS = ['cover_letter', 'resume_snapshot', 'portfolio', 'other'];

    public $timestamps = false;

    protected $fillable = ['application_id', 'kind', 'file_path', 'file_name', 'mime', 'size_bytes', 'uploaded_at'];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'size_bytes' => 'integer',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
```

- [ ] **Step 6: Factories**

`database/factories/ApplicationFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\CanonicalJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'canonical_job_id' => CanonicalJob::factory(),
            'search_profile_id' => null,
            'resume_id' => null,
            'status' => 'saved',
            'applied_at' => null,
            'next_action_at' => null,
            'notes' => null,
            'stage2_snapshot' => null,
        ];
    }

    public function applied(): self
    {
        return $this->state(['status' => 'applied', 'applied_at' => now()]);
    }
}
```

`database/factories/ApplicationEventFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ApplicationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationEventFactory extends Factory
{
    protected $model = ApplicationEvent::class;

    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'type' => 'status_change',
            'payload' => ['from' => null, 'to' => 'saved'],
            'occurred_at' => now(),
        ];
    }
}
```

`database/factories/ApplicationContactFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ApplicationContact;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationContactFactory extends Factory
{
    protected $model = ApplicationContact::class;

    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'name' => fake()->name(),
            'role' => 'recruiter',
            'email' => fake()->safeEmail(),
            'phone' => null,
            'linkedin_url' => null,
            'notes' => null,
        ];
    }
}
```

`database/factories/ApplicationDocumentFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ApplicationDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationDocumentFactory extends Factory
{
    protected $model = ApplicationDocument::class;

    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'kind' => 'cover_letter',
            'file_path' => 'applications/stub.pdf',
            'file_name' => 'cover-letter.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1024,
            'uploaded_at' => now(),
        ];
    }
}
```

- [ ] **Step 7: `User` HasMany**

Edit `app/Models/User.php` — append inside the class body:

```php
public function applications(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(Application::class);
}
```

- [ ] **Step 8: Seed additions**

Edit `database/seeders/SiteSettingsSeeder.php`. Append to `$defaults`:

```php
['key' => 'reminder_scan_hour_utc',          'value' => '9',   'type' => 'int', 'description' => 'UTC hour for ApplicationReminderScan.'],
['key' => 'nav_reminder_badge_ttl_seconds',  'value' => '60',  'type' => 'int', 'description' => 'How long to cache the nav-bar reminder badge count per user.'],
```

Edit `database/seeders/JobSourcesSeeder.php`. Append to `$seeds`:

```php
['source_type' => 'remotive',  'identifier' => null, 'display_name' => 'Remotive',              'config_json' => null],
['source_type' => 'usajobs',   'identifier' => null, 'display_name' => 'USAJobs (US Federal)',  'config_json' => null],
```

- [ ] **Step 9: Migrate + seed + verify**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan migrate --no-interaction
/c/Users/david/.config/herd/bin/php84/php.exe artisan db:seed --class=SiteSettingsSeeder --no-interaction
/c/Users/david/.config/herd/bin/php84/php.exe artisan db:seed --class=JobSourcesSeeder --no-interaction
```

Verify:

```bash
docker exec throughline-postgres psql -U throughline -d throughline -c "\d applications"
docker exec throughline-postgres psql -U throughline -d throughline -c "SELECT source_type FROM job_sources WHERE source_type IN ('remotive','usajobs');"
```

Expected: applications table exists with correct columns; 2 new job_sources rows.

- [ ] **Step 10: Full suite passes unchanged**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --compact
```

Expected: 134 pass (baseline).

- [ ] **Step 11: Format + commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_04_22_200000_create_applications_table.php database/migrations/2026_04_22_200100_create_application_events_table.php database/migrations/2026_04_22_200200_create_application_contacts_table.php database/migrations/2026_04_22_200300_create_application_documents_table.php app/Models/Application.php app/Models/ApplicationEvent.php app/Models/ApplicationContact.php app/Models/ApplicationDocument.php database/factories/ApplicationFactory.php database/factories/ApplicationEventFactory.php database/factories/ApplicationContactFactory.php database/factories/ApplicationDocumentFactory.php app/Models/User.php database/seeders/SiteSettingsSeeder.php database/seeders/JobSourcesSeeder.php
git commit -m "$(cat <<'EOF'
feat: application pipeline schema + models + seed additions

Four new tables: applications (unique on user_id+canonical_job_id,
8-status state machine, stage2_snapshot jsonb for save-time context
preservation), application_events (append-only, 6 event types),
application_contacts (recruiter/hiring_manager/interviewer/referral/
other), application_documents (cover_letter/resume_snapshot/portfolio/
other).

ApplicationEvent model enforces the append-only invariant via a
booted() updating-listener that throws LogicException.

Site settings gain reminder_scan_hour_utc + nav_reminder_badge_ttl.
JobSourcesSeeder appends Remotive + USAJobs rows (driver classes
land in Tasks 9 + 10).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `EventWriter` service + append-only tests

**Files:**
- Create: `app/Services/Applications/EventWriter.php`
- Create: `tests/Feature/Applications/EventWriterTest.php`
- Create: `tests/Feature/Applications/ApplicationEventAppendOnlyTest.php`

- [ ] **Step 1: Write the append-only enforcement test**

`tests/Feature/Applications/ApplicationEventAppendOnlyTest.php`:

```php
<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ApplicationEventAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_an_event_throws_logicexception(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();
        $event = ApplicationEvent::factory()->create(['application_id' => $app->id]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('append-only');

        $event->update(['type' => 'note']);
    }

    public function test_save_of_new_event_is_allowed(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();

        $event = ApplicationEvent::create([
            'application_id' => $app->id,
            'type' => 'status_change',
            'payload' => ['from' => null, 'to' => 'saved'],
            'occurred_at' => now(),
        ]);

        $this->assertNotNull($event->id);
        $this->assertSame('status_change', $event->fresh()->type);
    }
}
```

- [ ] **Step 2: Write the EventWriter tests**

`tests/Feature/Applications/EventWriterTest.php`:

```php
<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\ApplicationContact;
use App\Models\ApplicationDocument;
use App\Models\ApplicationEvent;
use App\Models\User;
use App\Services\Applications\EventWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventWriterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = app(EventWriter::class);
    }

    protected EventWriter $writer;

    public function test_log_status_change_writes_row_with_from_to_payload(): void
    {
        $app = Application::factory()->for(User::factory())->create(['status' => 'applied']);

        $event = $this->writer->logStatusChange($app, 'saved', 'applied');

        $this->assertSame('status_change', $event->type);
        $this->assertSame(['from' => 'saved', 'to' => 'applied'], $event->payload);
        $this->assertSame($app->id, $event->application_id);
    }

    public function test_log_status_change_handles_null_from(): void
    {
        $app = Application::factory()->for(User::factory())->create();

        $event = $this->writer->logStatusChange($app, null, 'saved');

        $this->assertSame(['from' => null, 'to' => 'saved'], $event->payload);
    }

    public function test_log_note_writes_body_payload(): void
    {
        $app = Application::factory()->for(User::factory())->create();

        $event = $this->writer->logNote($app, 'Had coffee with recruiter');

        $this->assertSame('note', $event->type);
        $this->assertSame(['body' => 'Had coffee with recruiter'], $event->payload);
    }

    public function test_log_contact_added_captures_contact_id_and_role(): void
    {
        $app = Application::factory()->for(User::factory())->create();
        $contact = ApplicationContact::factory()->for($app)->create(['role' => 'recruiter']);

        $event = $this->writer->logContactAdded($app, $contact);

        $this->assertSame('contact_added', $event->type);
        $this->assertSame(['contact_id' => $contact->id, 'role' => 'recruiter'], $event->payload);
    }

    public function test_log_document_attached_captures_document_id_and_kind(): void
    {
        $app = Application::factory()->for(User::factory())->create();
        $doc = ApplicationDocument::factory()->for($app)->create(['kind' => 'cover_letter']);

        $event = $this->writer->logDocumentAttached($app, $doc);

        $this->assertSame('document_attached', $event->type);
        $this->assertSame(['document_id' => $doc->id, 'kind' => 'cover_letter'], $event->payload);
    }

    public function test_log_reminder_writes_current_next_action_at(): void
    {
        $app = Application::factory()->for(User::factory())->create(['next_action_at' => now()->subDay()]);

        $event = $this->writer->logReminder($app);

        $this->assertSame('reminder', $event->type);
        $this->assertArrayHasKey('next_action_at', $event->payload);
    }

    public function test_log_interview_captures_details_payload(): void
    {
        $app = Application::factory()->for(User::factory())->create();

        $event = $this->writer->logInterview($app, ['stage' => 'phone_screen', 'scheduled_for' => '2026-05-01T10:00:00Z']);

        $this->assertSame('interview', $event->type);
        $this->assertSame('phone_screen', $event->payload['stage']);
    }
}
```

- [ ] **Step 3: Run — expect FAIL**

- [ ] **Step 4: Implement `EventWriter`**

`app/Services/Applications/EventWriter.php`:

```php
<?php

namespace App\Services\Applications;

use App\Models\Application;
use App\Models\ApplicationContact;
use App\Models\ApplicationDocument;
use App\Models\ApplicationEvent;

class EventWriter
{
    public function logStatusChange(Application $app, ?string $from, string $to): ApplicationEvent
    {
        return $this->create($app, 'status_change', ['from' => $from, 'to' => $to]);
    }

    public function logNote(Application $app, string $body): ApplicationEvent
    {
        return $this->create($app, 'note', ['body' => $body]);
    }

    public function logContactAdded(Application $app, ApplicationContact $contact): ApplicationEvent
    {
        return $this->create($app, 'contact_added', ['contact_id' => $contact->id, 'role' => $contact->role]);
    }

    public function logDocumentAttached(Application $app, ApplicationDocument $doc): ApplicationEvent
    {
        return $this->create($app, 'document_attached', ['document_id' => $doc->id, 'kind' => $doc->kind]);
    }

    public function logReminder(Application $app): ApplicationEvent
    {
        return $this->create($app, 'reminder', ['next_action_at' => $app->next_action_at?->toIso8601String()]);
    }

    public function logInterview(Application $app, array $details): ApplicationEvent
    {
        return $this->create($app, 'interview', $details);
    }

    protected function create(Application $app, string $type, array $payload): ApplicationEvent
    {
        return ApplicationEvent::create([
            'application_id' => $app->id,
            'type' => $type,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }
}
```

- [ ] **Step 5: Run — expect PASS**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter="EventWriterTest|ApplicationEventAppendOnlyTest"
```

Expected: 9 tests pass.

- [ ] **Step 6: Full suite**

Expected: 134 + 9 = 143 pass.

- [ ] **Step 7: Format + commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Applications/EventWriter.php tests/Feature/Applications
git commit -m "$(cat <<'EOF'
feat: EventWriter service + append-only invariant enforcement

EventWriter is the single writer of application_events rows. Seven
typed methods (logStatusChange, logNote, logContactAdded,
logDocumentAttached, logReminder, logInterview) produce the correct
payload shape for each event type. Controllers call EventWriter
inside their own DB transactions; the service doesn't manage
transactions itself.

ApplicationEvent's append-only invariant (boot-time updating listener
that throws LogicException) is covered by a dedicated test —
attempts to mutate any persisted event row fail loud.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Save-from-feed controller + feed card "Save" affordance

**Files:**
- Create: `app/Http/Controllers/Applications/ApplicationController.php` (stub + `store` method; other actions in Task 4)
- Create: `app/Http/Requests/Applications/StoreApplicationRequest.php`
- Modify: `routes/web.php` (add `applications` resource routes — just `store` for now, rest land in Task 4)
- Modify: `resources/js/components/feed/JobMatchCard.vue` (add Save button + handling of saved state)
- Modify: `app/Http/Controllers/FeedController.php` (add `is_saved` flag to each match payload so the card knows to render "Saved" state)
- Create: `tests/Feature/Applications/ApplicationSaveFromFeedTest.php`

- [ ] **Step 1: Feature tests**

`tests/Feature/Applications/ApplicationSaveFromFeedTest.php`:

```php
<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\CanonicalJob;
use App\Models\JobMatch;
use App\Models\Resume;
use App\Models\SearchProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationSaveFromFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function seedMatchFor(User $user, array $matchOverrides = []): array
    {
        $resume = Resume::factory()->for($user)->parsed()->create();
        $profile = SearchProfile::factory()->for($user)->create(['resume_id' => $resume->id]);
        $job = CanonicalJob::factory()->create();

        $matchId = \DB::table('job_matches')->insertGetId(array_merge([
            'user_id' => $user->id,
            'search_profile_id' => $profile->id,
            'canonical_job_id' => $job->id,
            'stage1_score' => 0.8,
            'stage2_score' => 85,
            'stage2_rationale' => 'Strong fit.',
            'stage2_gaps' => json_encode([['requirement' => 'Go', 'severity' => 'stretch']]),
            'stage2_narrative' => 'Natural next step.',
            'stage2_at' => now(),
            'created_at' => now(),
        ], $matchOverrides));

        return compact('resume', 'profile', 'job', 'matchId');
    }

    public function test_save_creates_application_with_stage2_snapshot_and_status_change_event(): void
    {
        $user = User::factory()->create();
        $ctx = $this->seedMatchFor($user);

        $this->actingAs($user)
            ->post(route('applications.store'), ['job_match_id' => $ctx['matchId']])
            ->assertRedirect()
            ->assertSessionHas('status');

        $app = Application::first();
        $this->assertSame($user->id, $app->user_id);
        $this->assertSame($ctx['job']->id, $app->canonical_job_id);
        $this->assertSame($ctx['profile']->id, $app->search_profile_id);
        $this->assertSame($ctx['resume']->id, $app->resume_id);
        $this->assertSame('saved', $app->status);

        $this->assertSame(85, $app->stage2_snapshot['fit_score']);
        $this->assertSame('Strong fit.', $app->stage2_snapshot['rationale']);

        $this->assertSame(1, $app->events()->count());
        $event = $app->events()->first();
        $this->assertSame('status_change', $event->type);
        $this->assertSame(['from' => null, 'to' => 'saved'], $event->payload);
    }

    public function test_duplicate_save_returns_409_with_existing_id(): void
    {
        $user = User::factory()->create();
        $ctx = $this->seedMatchFor($user);

        $this->actingAs($user)->post(route('applications.store'), ['job_match_id' => $ctx['matchId']])->assertRedirect();

        $response = $this->actingAs($user)->post(route('applications.store'), ['job_match_id' => $ctx['matchId']]);
        $response->assertStatus(409);
    }

    public function test_save_requires_resume_on_profile(): void
    {
        $user = User::factory()->create();
        $profile = SearchProfile::factory()->for($user)->create(['resume_id' => null]);
        $job = CanonicalJob::factory()->create();

        $matchId = \DB::table('job_matches')->insertGetId([
            'user_id' => $user->id,
            'search_profile_id' => $profile->id,
            'canonical_job_id' => $job->id,
            'stage1_score' => 0.8,
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('applications.store'), ['job_match_id' => $matchId])
            ->assertStatus(422);
    }

    public function test_stranger_cannot_save_anothers_match(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $ctx = $this->seedMatchFor($owner);

        $this->actingAs($stranger)
            ->post(route('applications.store'), ['job_match_id' => $ctx['matchId']])
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Form request**

`app/Http/Requests/Applications/StoreApplicationRequest.php`:

```php
<?php

namespace App\Http\Requests\Applications;

use Illuminate\Foundation\Http\FormRequest;

class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'job_match_id' => ['required', 'integer', 'exists:job_matches,id'],
        ];
    }
}
```

- [ ] **Step 3: Controller with `store`**

`app/Http/Controllers/Applications/ApplicationController.php`:

```php
<?php

namespace App\Http\Controllers\Applications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\StoreApplicationRequest;
use App\Models\Application;
use App\Models\JobMatch;
use App\Services\Applications\EventWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class ApplicationController extends Controller
{
    public function __construct(protected EventWriter $events)
    {
    }

    public function store(StoreApplicationRequest $request): RedirectResponse|JsonResponse
    {
        $match = JobMatch::with('profile')->findOrFail($request->integer('job_match_id'));

        abort_unless((int) $match->user_id === $request->user()->id, 403);

        if (!$match->profile?->resume_id) {
            return response()->json(['message' => 'Link a resume to this search profile before saving.'], 422);
        }

        $existing = Application::where('user_id', $match->user_id)
            ->where('canonical_job_id', $match->canonical_job_id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Already saved.', 'application_id' => $existing->id], 409);
        }

        $snapshot = [
            'fit_score' => $match->stage2_score,
            'rationale' => $match->stage2_rationale,
            'gaps' => $match->stage2_gaps,
            'narrative' => $match->stage2_narrative,
            'stage1_score' => (float) $match->stage1_score,
            'snapshot_at' => now()->toIso8601String(),
        ];

        $app = DB::transaction(function () use ($match, $snapshot) {
            $app = Application::create([
                'user_id' => $match->user_id,
                'canonical_job_id' => $match->canonical_job_id,
                'search_profile_id' => $match->search_profile_id,
                'resume_id' => $match->profile->resume_id,
                'status' => 'saved',
                'stage2_snapshot' => $snapshot,
            ]);

            $this->events->logStatusChange($app, null, 'saved');

            return $app;
        });

        return redirect()->back()->with('status', 'Saved to your applications.')->with('applicationId', $app->id);
    }
}
```

- [ ] **Step 4: Route**

Edit `routes/web.php`. Inside the auth middleware group:

```php
use App\Http\Controllers\Applications\ApplicationController;

Route::post('/applications', [ApplicationController::class, 'store'])->name('applications.store');
```

(Task 4 adds the rest of the resource routes.)

- [ ] **Step 5: Extend `FeedController` to flag saved matches**

Edit `app/Http/Controllers/FeedController.php`. Extend the paginated query to include a left-join or sub-select that flags which matches have an existing application. Simplest: after running the paginate, load a set of canonical_job_ids the user already saved and filter in-memory.

Replace the paginate result's `through()` call body to include `is_saved`:

```php
// Before paginate block — load the set of canonical_job_ids the user has already saved:
$savedJobIds = \DB::table('applications')
    ->where('user_id', $request->user()->id)
    ->pluck('canonical_job_id')
    ->flip();

// ... in the through() callback, add:
'is_saved' => $savedJobIds->has($row->job_id),
```

- [ ] **Step 6: Update `JobMatchCard.vue`**

Add a `is_saved` field to the TS type, and a Save button / Saved indicator. In the header `<div class="flex items-center gap-3">` block, next to the title:

```vue
<button
  v-if="!match.is_saved"
  type="button"
  class="rounded-full border border-ink-3 px-3 py-0.5 text-xs hover:border-oxblood hover:text-oxblood"
  @click="save"
>
  Save
</button>
<span
  v-else
  class="rounded-full border border-oxblood bg-oxblood/10 px-3 py-0.5 text-xs text-oxblood"
>
  Saved
</span>
```

Add the matching script method:

```ts
function save() {
  router.post(route('applications.store'), { job_match_id: props.match.match_id }, {
    preserveScroll: true,
    onSuccess: () => router.reload({ only: ['matches'] }),
  });
}
```

Add `is_saved: boolean` to the `match` prop TS shape.

- [ ] **Step 7: Run tests**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=ApplicationSaveFromFeedTest
```

Expected: 4 pass.

Full suite expected: 143 + 4 = 147 pass.

- [ ] **Step 8: Format + commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Applications app/Http/Requests/Applications routes/web.php app/Http/Controllers/FeedController.php resources/js/components/feed/JobMatchCard.vue tests/Feature/Applications/ApplicationSaveFromFeedTest.php
git commit -m "$(cat <<'EOF'
feat: save-from-feed — applications.store + card Save button

Clicking Save on a JobMatchCard POSTs to /applications with the
job_match_id. Controller snapshots stage-2 data (fit score, rationale,
gaps, narrative, stage1 score, timestamp) into applications.
stage2_snapshot so later reranks can't retcon user history. Wraps the
insert + first status_change event in a DB transaction via EventWriter.

Duplicate save returns 409 with the existing application id. Missing
resume on the profile returns 422 with a clear message. Stranger
attempting to save another user's match gets 403.

Feed controller now injects is_saved per match so the card renders
'Saved' indicator instead of the Save button on re-render.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Application list + detail controllers + base UI

**Files:**
- Modify: `app/Http/Controllers/Applications/ApplicationController.php` (add index/show/update/destroy)
- Modify: `routes/web.php` (add remaining application routes)
- Create: `resources/js/pages/applications/Index.vue` (list view — kanban structure without drag; drag lands in Task 5)
- Create: `resources/js/pages/applications/Show.vue` (detail with timeline)
- Create: `resources/js/components/applications/ApplicationCard.vue`
- Modify: `resources/js/components/AppSidebar.vue` (add "Applications" nav entry)
- Create: `tests/Feature/Applications/ApplicationControllerTest.php`

- [ ] **Step 1: Feature tests**

`tests/Feature/Applications/ApplicationControllerTest.php`:

```php
<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_shows_only_own_applications_grouped_by_status(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();

        Application::factory()->for($user)->create(['status' => 'saved']);
        Application::factory()->for($user)->applied()->create();
        Application::factory()->for($stranger)->create(['status' => 'saved']);

        $response = $this->actingAs($user)->get(route('applications.index'));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertCount(1, $props['columns']['saved']);
        $this->assertCount(1, $props['columns']['applied']);
        $this->assertCount(0, $props['columns']['rejected']);
    }

    public function test_show_returns_application_with_events_timeline(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();
        ApplicationEvent::factory()->for($app)->create(['type' => 'status_change', 'payload' => ['from' => null, 'to' => 'saved']]);
        ApplicationEvent::factory()->for($app)->create(['type' => 'note', 'payload' => ['body' => 'First call done']]);

        $response = $this->actingAs($user)->get(route('applications.show', $app));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertCount(2, $props['application']['events']);
    }

    public function test_show_forbids_stranger(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $app = Application::factory()->for($owner)->create();

        $this->actingAs($stranger)->get(route('applications.show', $app))->assertForbidden();
    }

    public function test_update_notes_writes_note_event(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();

        $this->actingAs($user)
            ->patch(route('applications.update', $app), ['notes' => 'New context'])
            ->assertRedirect();

        $app->refresh();
        $this->assertSame('New context', $app->notes);
        $this->assertSame(1, $app->events()->where('type', 'note')->count());
    }

    public function test_destroy_sets_status_withdrawn_and_writes_event(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create(['status' => 'applied']);

        $this->actingAs($user)->delete(route('applications.destroy', $app))->assertRedirect();

        $app->refresh();
        $this->assertSame('withdrawn', $app->status);
        $this->assertSame(1, $app->events()->where('type', 'status_change')->where('payload->to', 'withdrawn')->count());
    }
}
```

- [ ] **Step 2: Extend controller**

Add to `app/Http/Controllers/Applications/ApplicationController.php`:

```php
use App\Models\Application;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

public function index(Request $request): Response
{
    $applications = $request->user()->applications()
        ->with(['canonicalJob:id,title,company_name,apply_url,posted_at', 'resume:id,name'])
        ->orderByDesc('updated_at')
        ->get();

    $columns = collect(Application::STATUSES)
        ->mapWithKeys(fn ($status) => [$status => []])
        ->toArray();

    foreach ($applications as $app) {
        $columns[$app->status][] = $this->cardPayload($app);
    }

    return Inertia::render('applications/Index', [
        'columns' => $columns,
        'statuses' => Application::STATUSES,
        'terminalStatuses' => Application::TERMINAL_STATUSES,
    ]);
}

public function show(Application $application, Request $request): Response
{
    abort_unless((int) $application->user_id === $request->user()->id, 403);

    $application->load([
        'canonicalJob:id,title,company_name,location_raw,remote_mode,apply_url,posted_at',
        'resume:id,name',
        'events',
        'contacts',
        'documents',
    ]);

    return Inertia::render('applications/Show', [
        'application' => [
            'id' => $application->id,
            'status' => $application->status,
            'applied_at' => $application->applied_at,
            'next_action_at' => $application->next_action_at,
            'notes' => $application->notes,
            'stage2_snapshot' => $application->stage2_snapshot,
            'canonical_job' => $application->canonicalJob,
            'resume' => $application->resume,
            'events' => $application->events,
            'contacts' => $application->contacts,
            'documents' => $application->documents,
            'is_terminal' => $application->isTerminal(),
        ],
    ]);
}

public function update(Application $application, Request $request): \Illuminate\Http\RedirectResponse
{
    abort_unless((int) $application->user_id === $request->user()->id, 403);

    $data = $request->validate([
        'status' => ['nullable', \Illuminate\Validation\Rule::in(Application::STATUSES)],
        'notes' => ['nullable', 'string', 'max:10000'],
        'next_action_at' => ['nullable', 'date'],
    ]);

    \DB::transaction(function () use ($application, $data) {
        if (array_key_exists('status', $data) && $data['status'] !== null && $data['status'] !== $application->status) {
            $from = $application->status;
            $application->status = $data['status'];
            if ($data['status'] === 'applied' && !$application->applied_at) {
                $application->applied_at = now();
            }
            $this->events->logStatusChange($application, $from, $data['status']);
        }

        if (array_key_exists('notes', $data) && $data['notes'] !== $application->notes) {
            $application->notes = $data['notes'];
            if ($data['notes']) {
                $this->events->logNote($application, $data['notes']);
            }
        }

        if (array_key_exists('next_action_at', $data)) {
            $application->next_action_at = $data['next_action_at'];
        }

        $application->save();
    });

    return redirect()->back()->with('status', 'Application updated.');
}

public function destroy(Application $application, Request $request): \Illuminate\Http\RedirectResponse
{
    abort_unless((int) $application->user_id === $request->user()->id, 403);

    \DB::transaction(function () use ($application) {
        $from = $application->status;
        $application->status = 'withdrawn';
        $application->save();
        $this->events->logStatusChange($application, $from, 'withdrawn');
    });

    return redirect()->route('applications.index')->with('status', 'Application withdrawn.');
}

protected function cardPayload(Application $app): array
{
    return [
        'id' => $app->id,
        'status' => $app->status,
        'applied_at' => $app->applied_at?->toIso8601String(),
        'next_action_at' => $app->next_action_at?->toIso8601String(),
        'fit_score' => $app->stage2_snapshot['fit_score'] ?? null,
        'canonical_job' => [
            'id' => $app->canonicalJob->id,
            'title' => $app->canonicalJob->title,
            'company_name' => $app->canonicalJob->company_name,
        ],
    ];
}
```

Note: the existing `store` method (Task 3) stays intact.

- [ ] **Step 3: Routes**

Edit `routes/web.php`. Replace the single `applications.store` line with the full resource:

```php
use App\Http\Controllers\Applications\ApplicationController;

Route::resource('applications', ApplicationController::class)->except(['create', 'edit']);
```

- [ ] **Step 4: Vue `Index.vue`**

`resources/js/pages/applications/Index.vue`:

```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import ApplicationCard from '@/components/applications/ApplicationCard.vue';
import { ref } from 'vue';

type Card = {
  id: number;
  status: string;
  applied_at: string | null;
  next_action_at: string | null;
  fit_score: number | null;
  canonical_job: { id: number; title: string; company_name: string };
};

defineProps<{
  columns: Record<string, Card[]>;
  statuses: string[];
  terminalStatuses: string[];
}>();

const statusLabels: Record<string, string> = {
  saved: 'Saved',
  applied: 'Applied',
  phone_screen: 'Phone screen',
  technical: 'Technical',
  onsite: 'On-site',
  offer: 'Offer',
  rejected: 'Rejected',
  withdrawn: 'Withdrawn',
};

const collapsedTerminal = ref(true);
</script>

<template>
  <Head title="Applications" />
  <AppLayout>
    <div class="mx-auto max-w-7xl px-6 py-10">
      <header class="mb-8">
        <h1 class="font-display text-4xl text-ink">Applications</h1>
        <p class="mt-1 text-ink-3">Track saves, outreach, and outcomes.</p>
      </header>

      <!-- Active columns -->
      <div class="grid gap-4 md:grid-cols-3 lg:grid-cols-5">
        <div v-for="status in statuses.filter((s) => !terminalStatuses.includes(s))" :key="status" class="rounded-lg border border-hairline bg-bone-2/40 p-3">
          <header class="mb-3 flex items-center justify-between">
            <h2 class="font-medium text-ink">{{ statusLabels[status] }}</h2>
            <span class="text-xs text-ink-3">{{ columns[status].length }}</span>
          </header>
          <ul class="space-y-2">
            <li v-for="card in columns[status]" :key="card.id">
              <ApplicationCard :card="card" />
            </li>
          </ul>
        </div>
      </div>

      <!-- Terminal (collapsed by default) -->
      <section class="mt-8">
        <button type="button" class="text-sm text-ink-3 hover:text-oxblood" @click="collapsedTerminal = !collapsedTerminal">
          {{ collapsedTerminal ? 'Show' : 'Hide' }} terminal statuses
        </button>
        <div v-if="!collapsedTerminal" class="mt-4 grid gap-4 md:grid-cols-3">
          <div v-for="status in terminalStatuses" :key="status" class="rounded-lg border border-hairline bg-bone-2/40 p-3">
            <header class="mb-3 flex items-center justify-between">
              <h2 class="font-medium text-ink">{{ statusLabels[status] }}</h2>
              <span class="text-xs text-ink-3">{{ columns[status].length }}</span>
            </header>
            <ul class="space-y-2">
              <li v-for="card in columns[status]" :key="card.id">
                <ApplicationCard :card="card" />
              </li>
            </ul>
          </div>
        </div>
      </section>

      <div v-if="Object.values(columns).every((col) => col.length === 0)" class="mt-10 rounded-lg border border-hairline bg-bone-2 px-6 py-12 text-center">
        <p class="text-ink-2">No saved jobs yet — find one in the feed.</p>
      </div>
    </div>
  </AppLayout>
</template>
```

- [ ] **Step 5: `ApplicationCard.vue`**

`resources/js/components/applications/ApplicationCard.vue`:

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
  card: {
    id: number;
    status: string;
    applied_at: string | null;
    next_action_at: string | null;
    fit_score: number | null;
    canonical_job: { id: number; title: string; company_name: string };
  };
}>();
</script>

<template>
  <Link :href="route('applications.show', card.id)" class="block rounded border border-hairline bg-bone px-3 py-2 hover:border-oxblood">
    <div class="flex items-start justify-between gap-2">
      <div class="min-w-0">
        <p class="truncate text-sm font-medium text-ink">{{ card.canonical_job.title }}</p>
        <p class="truncate text-xs text-ink-3">{{ card.canonical_job.company_name }}</p>
      </div>
      <span v-if="card.fit_score !== null" class="shrink-0 rounded-full bg-oxblood/15 px-2 py-0.5 text-[11px] font-medium text-oxblood">{{ card.fit_score }}</span>
    </div>
    <div class="mt-2 flex items-center justify-between text-[11px] text-ink-3">
      <span v-if="card.applied_at">Applied {{ new Date(card.applied_at).toLocaleDateString() }}</span>
      <span v-if="card.next_action_at" class="text-oxblood">Due {{ new Date(card.next_action_at).toLocaleDateString() }}</span>
    </div>
  </Link>
</template>
```

- [ ] **Step 6: `Show.vue` (detail with timeline)**

`resources/js/pages/applications/Show.vue`:

```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

defineProps<{
  application: {
    id: number;
    status: string;
    applied_at: string | null;
    next_action_at: string | null;
    notes: string | null;
    stage2_snapshot: Record<string, any> | null;
    canonical_job: Record<string, any>;
    resume: { id: number; name: string } | null;
    events: Array<{ id: number; type: string; payload: Record<string, any>; occurred_at: string }>;
    contacts: Array<any>;
    documents: Array<any>;
    is_terminal: boolean;
  };
}>();

function withdraw() {
  if (window.confirm('Withdraw this application?')) {
    router.delete(route('applications.destroy', props.application.id));
  }
}

const notesForm = useForm({ notes: props.application.notes ?? '' });
function saveNotes() {
  notesForm.patch(route('applications.update', props.application.id));
}
</script>

<template>
  <Head :title="application.canonical_job.title" />
  <AppLayout>
    <div class="mx-auto max-w-4xl px-6 py-10">
      <Link :href="route('applications.index')" class="text-sm text-ink-3 hover:text-ink">&larr; All applications</Link>

      <header class="mt-4 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="font-display text-3xl text-ink">{{ application.canonical_job.title }}</h1>
          <p class="text-ink-2">{{ application.canonical_job.company_name }}</p>
        </div>
        <div class="flex items-center gap-2">
          <span class="rounded-full border border-ink-3 px-3 py-1 text-xs text-ink-2">{{ application.status }}</span>
          <a :href="application.canonical_job.apply_url" target="_blank" rel="noopener" class="text-sm text-oxblood hover:underline">Apply posting &rarr;</a>
        </div>
      </header>

      <section v-if="application.stage2_snapshot" class="mt-6 rounded border border-hairline bg-bone-2 px-5 py-4">
        <h2 class="text-sm font-medium text-ink-2">Rationale at save-time</h2>
        <p v-if="application.stage2_snapshot.narrative" class="mt-2 text-sm text-ink">{{ application.stage2_snapshot.narrative }}</p>
        <p v-if="application.stage2_snapshot.rationale" class="mt-1 text-sm text-ink-2">{{ application.stage2_snapshot.rationale }}</p>
      </section>

      <section class="mt-6">
        <h2 class="font-display text-xl text-ink">Notes</h2>
        <form class="mt-2 space-y-2" @submit.prevent="saveNotes">
          <textarea v-model="notesForm.notes" rows="4" class="w-full rounded border border-hairline bg-bone-2 px-3 py-2 text-sm"></textarea>
          <div class="flex justify-end">
            <button type="submit" class="rounded-full bg-oxblood px-4 py-1 text-sm font-medium text-bone" :disabled="notesForm.processing">Save</button>
          </div>
        </form>
      </section>

      <section class="mt-8">
        <h2 class="font-display text-xl text-ink">Timeline</h2>
        <ul class="mt-3 space-y-2">
          <li v-for="event in application.events" :key="event.id" class="flex gap-3 border-l border-hairline pl-3 text-sm">
            <span class="w-32 shrink-0 text-ink-3">{{ new Date(event.occurred_at).toLocaleString() }}</span>
            <span class="text-ink-2">
              <span v-if="event.type === 'status_change'">
                Status: {{ event.payload.from ?? '—' }} → {{ event.payload.to }}
              </span>
              <span v-else-if="event.type === 'note'">Note: {{ event.payload.body }}</span>
              <span v-else>{{ event.type }}</span>
            </span>
          </li>
        </ul>
      </section>

      <div v-if="!application.is_terminal" class="mt-10 flex justify-end">
        <button type="button" class="text-sm text-ink-3 hover:text-oxblood" @click="withdraw">Withdraw application</button>
      </div>
    </div>
  </AppLayout>
</template>
```

- [ ] **Step 7: Nav entry**

Edit `resources/js/components/AppSidebar.vue`. In the `mainNavItems` array, after the existing "Search profiles" entry, add:

```ts
{ title: 'Applications', href: '/applications', icon: ClipboardList },
```

And add the `ClipboardList` import at the top of the `<script>` block alongside other `lucide-vue-next` icons.

- [ ] **Step 8: Run tests**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=ApplicationControllerTest
```

Expected: 5 pass. Full suite expected: 147 + 5 = 152.

- [ ] **Step 9: Format + commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Applications/ApplicationController.php routes/web.php resources/js/pages/applications resources/js/components/applications resources/js/components/AppSidebar.vue tests/Feature/Applications/ApplicationControllerTest.php
git commit -m "$(cat <<'EOF'
feat: application list + detail UI (kanban structure, no drag yet)

ApplicationController gains index (kanban columns grouped by status),
show (detail with events timeline + stage2 snapshot + notes editor +
withdraw button), update (status/notes/next_action_at with event
writes inside DB transactions), destroy (soft-withdraw via status).

Index renders 5 active-status columns plus a collapsed terminal-status
section so the kanban stays scannable for active job seekers. Drag-
and-drop lands in Task 5.

ApplicationCard shows title, company, fit score, applied/due dates.
Detail page surfaces rationale-at-save-time from stage2_snapshot,
event timeline, and a notes editor. Withdraw button sets status to
'withdrawn' and records the event.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Kanban drag-and-drop + status transition tests

**Files:**
- Modify: `package.json` (add `vuedraggable@^4.1.0`)
- Modify: `resources/js/pages/applications/Index.vue` (wrap lists in `draggable` component)
- Create: `tests/Feature/Applications/ApplicationStatusTransitionTest.php`

- [ ] **Step 1: Install vuedraggable**

```bash
npm install --save vuedraggable@^4.1.0
```

- [ ] **Step 2: Feature tests for status transitions**

`tests/Feature/Applications/ApplicationStatusTransitionTest.php`:

```php
<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_patch_status_moves_and_writes_event(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create(['status' => 'saved']);

        $this->actingAs($user)->patch(route('applications.update', $app), ['status' => 'applied'])->assertRedirect();

        $app->refresh();
        $this->assertSame('applied', $app->status);
        $this->assertNotNull($app->applied_at);

        $event = $app->events()->where('type', 'status_change')->first();
        $this->assertSame('saved', $event->payload['from']);
        $this->assertSame('applied', $event->payload['to']);
    }

    public function test_patch_preserves_applied_at_on_later_transitions(): void
    {
        $user = User::factory()->create();
        $appliedTime = now()->subDays(3);
        $app = Application::factory()->for($user)->create(['status' => 'applied', 'applied_at' => $appliedTime]);

        $this->actingAs($user)->patch(route('applications.update', $app), ['status' => 'phone_screen']);

        $app->refresh();
        $this->assertTrue($app->applied_at->equalTo($appliedTime));
    }

    public function test_same_status_is_noop_no_event_written(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create(['status' => 'saved']);

        $this->actingAs($user)->patch(route('applications.update', $app), ['status' => 'saved'])->assertRedirect();

        $this->assertSame(0, $app->events()->count());
    }

    public function test_invalid_status_rejected(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create(['status' => 'saved']);

        $this->actingAs($user)
            ->patch(route('applications.update', $app), ['status' => 'bogus'])
            ->assertSessionHasErrors('status');
    }

    public function test_stranger_cannot_transition(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $app = Application::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->patch(route('applications.update', $app), ['status' => 'applied'])
            ->assertForbidden();
    }
}
```

- [ ] **Step 3: Run tests**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=ApplicationStatusTransitionTest
```

Expected: 5 pass (the update method from Task 4 already supports the status-only PATCH).

- [ ] **Step 4: Wire `vuedraggable` into `Index.vue`**

Replace the `<ul>` inside each active column with a `<draggable>` component. Import at top of `<script>`:

```ts
import draggable from 'vuedraggable';
```

Replace each column's list (both active and terminal sections):

```vue
<draggable
  v-model="columns[status]"
  group="applications"
  item-key="id"
  class="space-y-2"
  @change="(e: any) => onDrop(e, status)"
>
  <template #item="{ element: card }">
    <ApplicationCard :card="card" />
  </template>
</draggable>
```

Add the handler:

```ts
import { router } from '@inertiajs/vue3';

function onDrop(event: any, destinationStatus: string) {
  if (event.added) {
    const card = event.added.element;
    card.status = destinationStatus;
    router.patch(
      route('applications.update', card.id),
      { status: destinationStatus },
      {
        preserveScroll: true,
        preserveState: true,
        onError: () => {
          // Revert UI on failure — reload to recanonicalize.
          router.reload({ only: ['columns'] });
        },
      },
    );
  }
}
```

- [ ] **Step 5: Run the existing test suite**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --compact
```

Full suite expected: 152 + 5 = 157 pass. No UI test regressions — the kanban page renders with `draggable` even under SSR because vuedraggable degrades to a plain list.

- [ ] **Step 6: Format + commit**

```bash
npm run format
vendor/bin/pint --dirty
git add package.json package-lock.json resources/js/pages/applications/Index.vue tests/Feature/Applications/ApplicationStatusTransitionTest.php
git commit -m "$(cat <<'EOF'
feat: kanban drag-and-drop wires vuedraggable to status PATCH

Each column is now a <draggable> list. Dropping a card into a
different column emits a PATCH to /applications/{id} with the new
status; the existing update() method from Task 4 writes the
status_change event inside a DB transaction. Failed drops revert via
Inertia reload.

Status transition tests cover the happy path, applied_at preservation
across later moves, no-op on same-status patch, invalid status
rejection, and stranger forbidden.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Contacts CRUD

**Files:**
- Create: `app/Http/Controllers/Applications/ContactController.php`
- Create: `app/Http/Requests/Applications/StoreContactRequest.php`
- Create: `app/Http/Requests/Applications/UpdateContactRequest.php`
- Modify: `routes/web.php`
- Modify: `resources/js/pages/applications/Show.vue` (add contacts section + inline form)
- Create: `tests/Feature/Applications/ApplicationContactCrudTest.php`

- [ ] **Step 1: Form requests**

`app/Http/Requests/Applications/StoreContactRequest.php`:

```php
<?php

namespace App\Http\Requests\Applications;

use App\Models\ApplicationContact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && (int) $this->route('application')->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'role' => ['required', Rule::in(ApplicationContact::ROLES)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
```

`app/Http/Requests/Applications/UpdateContactRequest.php`:

```php
<?php

namespace App\Http\Requests\Applications;

class UpdateContactRequest extends StoreContactRequest
{
    // Same rules + same authorize.
}
```

- [ ] **Step 2: Controller**

`app/Http/Controllers/Applications/ContactController.php`:

```php
<?php

namespace App\Http\Controllers\Applications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\StoreContactRequest;
use App\Http\Requests\Applications\UpdateContactRequest;
use App\Models\Application;
use App\Models\ApplicationContact;
use App\Services\Applications\EventWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class ContactController extends Controller
{
    public function __construct(protected EventWriter $events)
    {
    }

    public function store(StoreContactRequest $request, Application $application): RedirectResponse
    {
        DB::transaction(function () use ($request, $application) {
            $contact = $application->contacts()->create($request->validated());
            $this->events->logContactAdded($application, $contact);
        });

        return redirect()->back()->with('status', 'Contact added.');
    }

    public function update(UpdateContactRequest $request, Application $application, ApplicationContact $contact): RedirectResponse
    {
        abort_unless($contact->application_id === $application->id, 404);

        $contact->update($request->validated());

        return redirect()->back()->with('status', 'Contact updated.');
    }

    public function destroy(Application $application, ApplicationContact $contact, \Illuminate\Http\Request $request): RedirectResponse
    {
        abort_unless((int) $application->user_id === $request->user()->id, 403);
        abort_unless($contact->application_id === $application->id, 404);

        $contact->delete();

        return redirect()->back()->with('status', 'Contact removed.');
    }
}
```

- [ ] **Step 3: Routes**

Edit `routes/web.php`:

```php
use App\Http\Controllers\Applications\ContactController;

Route::post('/applications/{application}/contacts', [ContactController::class, 'store'])->name('applications.contacts.store');
Route::patch('/applications/{application}/contacts/{contact}', [ContactController::class, 'update'])->name('applications.contacts.update');
Route::delete('/applications/{application}/contacts/{contact}', [ContactController::class, 'destroy'])->name('applications.contacts.destroy');
```

- [ ] **Step 4: Extend `Show.vue` with contacts section**

In `resources/js/pages/applications/Show.vue`, after the notes section, add:

```vue
<section class="mt-8">
  <h2 class="font-display text-xl text-ink">Contacts</h2>
  <ul v-if="application.contacts.length" class="mt-3 space-y-2">
    <li v-for="contact in application.contacts" :key="contact.id" class="flex items-start justify-between rounded border border-hairline bg-bone-2/50 px-4 py-3">
      <div>
        <p class="text-sm font-medium text-ink">{{ contact.name }} <span class="text-ink-3">· {{ contact.role }}</span></p>
        <p v-if="contact.email" class="text-xs text-ink-3">{{ contact.email }}</p>
        <p v-if="contact.linkedin_url" class="text-xs text-ink-3">
          <a :href="contact.linkedin_url" target="_blank" rel="noopener" class="hover:underline">LinkedIn</a>
        </p>
        <p v-if="contact.notes" class="mt-1 text-xs text-ink-2">{{ contact.notes }}</p>
      </div>
      <button type="button" class="text-xs text-ink-3 hover:text-oxblood" @click="removeContact(contact.id)">Remove</button>
    </li>
  </ul>
  <form class="mt-3 space-y-2" @submit.prevent="addContact">
    <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
      <input v-model="contactForm.name" placeholder="Name" class="rounded border border-hairline bg-bone-2 px-3 py-2 text-sm" />
      <select v-model="contactForm.role" class="rounded border border-hairline bg-bone-2 px-3 py-2 text-sm">
        <option value="recruiter">Recruiter</option>
        <option value="hiring_manager">Hiring manager</option>
        <option value="interviewer">Interviewer</option>
        <option value="referral">Referral</option>
        <option value="other">Other</option>
      </select>
      <input v-model="contactForm.email" type="email" placeholder="Email (optional)" class="rounded border border-hairline bg-bone-2 px-3 py-2 text-sm" />
    </div>
    <button type="submit" class="rounded-full bg-oxblood px-4 py-1 text-sm font-medium text-bone" :disabled="contactForm.processing">Add contact</button>
  </form>
</section>
```

Add to the script block:

```ts
const contactForm = useForm({ name: '', role: 'recruiter', email: '', phone: '', linkedin_url: '', notes: '' });
function addContact() {
  contactForm.post(route('applications.contacts.store', props.application.id), {
    onSuccess: () => contactForm.reset(),
  });
}
function removeContact(contactId: number) {
  if (window.confirm('Remove this contact?')) {
    router.delete(route('applications.contacts.destroy', [props.application.id, contactId]));
  }
}
```

- [ ] **Step 5: Feature tests**

`tests/Feature/Applications/ApplicationContactCrudTest.php`:

```php
<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\ApplicationContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationContactCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_contact_writes_event(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('applications.contacts.store', $app), [
                'name' => 'Jane Recruiter',
                'role' => 'recruiter',
                'email' => 'jane@example.com',
            ])
            ->assertRedirect();

        $this->assertSame(1, $app->contacts()->count());
        $this->assertSame(1, $app->events()->where('type', 'contact_added')->count());
    }

    public function test_update_contact(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();
        $contact = ApplicationContact::factory()->for($app)->create(['name' => 'Old name', 'role' => 'recruiter']);

        $this->actingAs($user)
            ->patch(route('applications.contacts.update', [$app, $contact]), [
                'name' => 'New name', 'role' => 'hiring_manager',
            ])
            ->assertRedirect();

        $this->assertSame('New name', $contact->fresh()->name);
        $this->assertSame('hiring_manager', $contact->fresh()->role);
    }

    public function test_delete_contact(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();
        $contact = ApplicationContact::factory()->for($app)->create();

        $this->actingAs($user)->delete(route('applications.contacts.destroy', [$app, $contact]))->assertRedirect();

        $this->assertNull($contact->fresh());
    }

    public function test_stranger_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $app = Application::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->post(route('applications.contacts.store', $app), ['name' => 'X', 'role' => 'recruiter'])
            ->assertForbidden();
    }

    public function test_invalid_role_rejected(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('applications.contacts.store', $app), ['name' => 'X', 'role' => 'bogus'])
            ->assertSessionHasErrors('role');
    }
}
```

- [ ] **Step 6: Run + commit**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=ApplicationContactCrudTest
```

Expected: 5 pass. Full suite: 157 + 5 = 162.

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Applications/ContactController.php app/Http/Requests/Applications routes/web.php resources/js/pages/applications/Show.vue tests/Feature/Applications/ApplicationContactCrudTest.php
git commit -m "feat: application contacts CRUD + inline form on detail page"
```

---

## Task 7: Documents upload + delete

**Files:**
- Create: `app/Http/Controllers/Applications/DocumentController.php`
- Create: `app/Http/Requests/Applications/StoreDocumentRequest.php`
- Modify: `routes/web.php`
- Modify: `resources/js/pages/applications/Show.vue` (add documents section)
- Create: `tests/Feature/Applications/ApplicationDocumentTest.php`

- [ ] **Step 1: Form request**

`app/Http/Requests/Applications/StoreDocumentRequest.php`:

```php
<?php

namespace App\Http\Requests\Applications;

use App\Models\ApplicationDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && (int) $this->route('application')->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(ApplicationDocument::KINDS)],
            'file' => ['required', 'file', 'mimetypes:application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/png,image/jpeg,text/plain', 'max:20480'],
        ];
    }
}
```

- [ ] **Step 2: Controller**

`app/Http/Controllers/Applications/DocumentController.php`:

```php
<?php

namespace App\Http\Controllers\Applications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\StoreDocumentRequest;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Services\Applications\EventWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function __construct(protected EventWriter $events)
    {
    }

    public function store(StoreDocumentRequest $request, Application $application): RedirectResponse
    {
        $file = $request->file('file');
        $path = $file->store('applications/' . $application->user_id . '/' . $application->id, 'local');

        DB::transaction(function () use ($application, $file, $path, $request) {
            $doc = $application->documents()->create([
                'kind' => $request->input('kind'),
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'uploaded_at' => now(),
            ]);

            $this->events->logDocumentAttached($application, $doc);
        });

        return redirect()->back()->with('status', 'Document attached.');
    }

    public function destroy(Application $application, ApplicationDocument $document, \Illuminate\Http\Request $request): RedirectResponse
    {
        abort_unless((int) $application->user_id === $request->user()->id, 403);
        abort_unless($document->application_id === $application->id, 404);

        $filePath = $document->file_path;
        $document->delete();

        if ($filePath) {
            try {
                Storage::disk('local')->delete($filePath);
            } catch (\Throwable $e) {
                \Log::warning('Document file cleanup failed', ['path' => $filePath, 'error' => $e->getMessage()]);
            }
        }

        return redirect()->back()->with('status', 'Document removed.');
    }
}
```

(Same DB-first, file-best-effort cleanup pattern from Plan 2 Task 5's `ResumeController::destroy` fix.)

- [ ] **Step 3: Routes**

```php
use App\Http\Controllers\Applications\DocumentController;

Route::post('/applications/{application}/documents', [DocumentController::class, 'store'])->name('applications.documents.store');
Route::delete('/applications/{application}/documents/{document}', [DocumentController::class, 'destroy'])->name('applications.documents.destroy');
```

- [ ] **Step 4: Extend `Show.vue`**

Add after the contacts section:

```vue
<section class="mt-8">
  <h2 class="font-display text-xl text-ink">Documents</h2>
  <ul v-if="application.documents.length" class="mt-3 space-y-2">
    <li v-for="doc in application.documents" :key="doc.id" class="flex items-center justify-between rounded border border-hairline bg-bone-2/50 px-4 py-2 text-sm">
      <div>
        <p class="font-medium text-ink">{{ doc.file_name }}</p>
        <p class="text-xs text-ink-3">{{ doc.kind }} · {{ Math.round(doc.size_bytes / 1024) }} KB</p>
      </div>
      <button type="button" class="text-xs text-ink-3 hover:text-oxblood" @click="removeDoc(doc.id)">Remove</button>
    </li>
  </ul>
  <form class="mt-3 flex items-center gap-2" @submit.prevent="uploadDoc">
    <select v-model="docForm.kind" class="rounded border border-hairline bg-bone-2 px-3 py-2 text-sm">
      <option value="cover_letter">Cover letter</option>
      <option value="resume_snapshot">Resume snapshot</option>
      <option value="portfolio">Portfolio</option>
      <option value="other">Other</option>
    </select>
    <input type="file" class="text-sm" @change="(e: any) => docForm.file = e.target.files[0]" />
    <button type="submit" class="rounded-full bg-oxblood px-4 py-1 text-sm font-medium text-bone" :disabled="docForm.processing">Upload</button>
  </form>
</section>
```

Script:

```ts
const docForm = useForm<{ kind: string; file: File | null }>({ kind: 'cover_letter', file: null });
function uploadDoc() {
  if (!docForm.file) return;
  docForm.post(route('applications.documents.store', props.application.id), {
    forceFormData: true,
    onSuccess: () => docForm.reset(),
  });
}
function removeDoc(docId: number) {
  if (window.confirm('Remove this document?')) {
    router.delete(route('applications.documents.destroy', [props.application.id, docId]));
  }
}
```

- [ ] **Step 5: Feature tests**

`tests/Feature/Applications/ApplicationDocumentTest.php`:

```php
<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicationDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_lands_on_disk_and_writes_event(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();
        $file = UploadedFile::fake()->create('cover.pdf', 50, 'application/pdf');

        $this->actingAs($user)
            ->post(route('applications.documents.store', $app), ['kind' => 'cover_letter', 'file' => $file])
            ->assertRedirect();

        $doc = $app->documents()->first();
        $this->assertSame('cover_letter', $doc->kind);
        Storage::disk('local')->assertExists($doc->file_path);
        $this->assertSame(1, $app->events()->where('type', 'document_attached')->count());
    }

    public function test_delete_removes_file_and_row(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();
        Storage::disk('local')->put('applications/stub.pdf', 'x');
        $doc = ApplicationDocument::factory()->for($app)->create(['file_path' => 'applications/stub.pdf']);

        $this->actingAs($user)->delete(route('applications.documents.destroy', [$app, $doc]))->assertRedirect();

        $this->assertNull($doc->fresh());
        Storage::disk('local')->assertMissing('applications/stub.pdf');
    }

    public function test_mime_validation_rejects_bad_files(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create();
        $file = UploadedFile::fake()->create('bad.exe', 10, 'application/octet-stream');

        $this->actingAs($user)
            ->post(route('applications.documents.store', $app), ['kind' => 'cover_letter', 'file' => $file])
            ->assertSessionHasErrors('file');
    }

    public function test_stranger_forbidden(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $app = Application::factory()->for($owner)->create();
        $file = UploadedFile::fake()->create('cover.pdf', 50, 'application/pdf');

        $this->actingAs($stranger)
            ->post(route('applications.documents.store', $app), ['kind' => 'cover_letter', 'file' => $file])
            ->assertForbidden();
    }
}
```

- [ ] **Step 6: Run + commit**

Expected: 4 pass. Full suite: 162 + 4 = 166.

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Applications/DocumentController.php app/Http/Requests/Applications/StoreDocumentRequest.php routes/web.php resources/js/pages/applications/Show.vue tests/Feature/Applications/ApplicationDocumentTest.php
git commit -m "feat: application documents — upload + delete + event writes"
```

---

## Task 8: Reminder scan + nav badge + rerun command

**Files:**
- Create: `app/Jobs/ApplicationReminderScan.php`
- Create: `app/Console/Commands/ReminderScanNowCommand.php`
- Modify: `routes/console.php` (add scheduler entry)
- Modify: `app/Http/Middleware/HandleInertiaRequests.php` (share `reminderBadgeCount` as a lazy prop)
- Modify: `resources/js/components/AppSidebar.vue` (render the badge on the Applications nav entry)
- Create: `tests/Feature/Applications/ApplicationReminderScanTest.php`

- [ ] **Step 1: Write the reminder scan test**

`tests/Feature/Applications/ApplicationReminderScanTest.php`:

```php
<?php

namespace Tests\Feature\Applications;

use App\Jobs\ApplicationReminderScan;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationReminderScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_applications_get_reminder_events(): void
    {
        $user = User::factory()->create();
        $overdue = Application::factory()->for($user)->create([
            'status' => 'applied',
            'next_action_at' => now()->subDays(2),
        ]);
        $notDueYet = Application::factory()->for($user)->create([
            'status' => 'applied',
            'next_action_at' => now()->addDays(2),
        ]);
        $terminal = Application::factory()->for($user)->create([
            'status' => 'rejected',
            'next_action_at' => now()->subDays(2),
        ]);

        (new ApplicationReminderScan())->handle();

        $this->assertSame(1, $overdue->events()->where('type', 'reminder')->count());
        $this->assertSame(0, $notDueYet->events()->where('type', 'reminder')->count());
        $this->assertSame(0, $terminal->events()->where('type', 'reminder')->count());
    }

    public function test_same_day_rerun_does_not_duplicate_reminder(): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->create([
            'status' => 'applied',
            'next_action_at' => now()->subDays(1),
        ]);

        (new ApplicationReminderScan())->handle();
        (new ApplicationReminderScan())->handle();

        $this->assertSame(1, $app->events()->where('type', 'reminder')->count());
    }
}
```

- [ ] **Step 2: Implement the job**

`app/Jobs/ApplicationReminderScan.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Application;
use App\Services\Applications\EventWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ApplicationReminderScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $writer = app(EventWriter::class);
        $nonTerminal = collect(Application::STATUSES)->diff(Application::TERMINAL_STATUSES)->values()->all();

        Application::whereIn('status', $nonTerminal)
            ->whereNotNull('next_action_at')
            ->where('next_action_at', '<=', now())
            ->chunkById(100, function ($apps) use ($writer) {
                foreach ($apps as $app) {
                    if ($this->alreadyRemindedToday($app)) {
                        continue;
                    }
                    $writer->logReminder($app);
                }
            });
    }

    protected function alreadyRemindedToday(Application $app): bool
    {
        return $app->events()
            ->where('type', 'reminder')
            ->whereDate('occurred_at', now()->toDateString())
            ->exists();
    }
}
```

- [ ] **Step 3: Artisan command**

`app/Console/Commands/ReminderScanNowCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\ApplicationReminderScan;
use Illuminate\Console\Command;

class ReminderScanNowCommand extends Command
{
    protected $signature = 'throughline:reminder-scan-now';
    protected $description = 'Run ApplicationReminderScan synchronously for debugging.';

    public function handle(): int
    {
        (new ApplicationReminderScan())->handle();
        $this->info('Reminder scan complete.');
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Scheduler wiring**

Edit `routes/console.php`. Append:

```php
use App\Jobs\ApplicationReminderScan;

Schedule::call(function () {
    $hour = (int) app(\App\Services\SiteSettings::class)->get('reminder_scan_hour_utc', '9');
    if (now()->utc()->hour === $hour && now()->utc()->minute === 0) {
        ApplicationReminderScan::dispatch();
    }
})->everyMinute()->name('throughline-reminder-scan-gate')->withoutOverlapping();
```

(Same gated-every-minute pattern from Plan 3 Task 5.)

- [ ] **Step 5: Share the badge count via Inertia**

Edit `app/Http/Middleware/HandleInertiaRequests.php`. In the `share()` method, add a `reminderBadgeCount` lazy prop:

```php
use App\Models\Application;
use Illuminate\Support\Facades\Cache;

// ... inside share():
'reminderBadgeCount' => fn () => $request->user()
    ? Cache::remember(
        "reminder_badge:{$request->user()->id}",
        (int) app(\App\Services\SiteSettings::class)->get('nav_reminder_badge_ttl_seconds', '60'),
        fn () => Application::where('user_id', $request->user()->id)
            ->whereNotIn('status', Application::TERMINAL_STATUSES)
            ->whereNotNull('next_action_at')
            ->where('next_action_at', '<=', now())
            ->count(),
    )
    : 0,
```

- [ ] **Step 6: Render the badge in `AppSidebar.vue`**

Update the Applications nav item to optionally render a badge. In the template where nav items are rendered, after the title/icon, conditionally show:

```vue
<span
  v-if="item.href === '/applications' && $page.props.reminderBadgeCount > 0"
  class="ml-auto rounded-full bg-oxblood px-2 py-0.5 text-[10px] font-medium text-bone"
>
  {{ $page.props.reminderBadgeCount }}
</span>
```

Adjust the selector/pattern based on Plan 2's AppSidebar render loop (it's a `v-for` over `mainNavItems`).

- [ ] **Step 7: Run tests**

Expected: 2 pass. Full suite: 166 + 2 = 168.

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --filter=ApplicationReminderScanTest
```

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add app/Jobs/ApplicationReminderScan.php app/Console/Commands/ReminderScanNowCommand.php routes/console.php app/Http/Middleware/HandleInertiaRequests.php resources/js/components/AppSidebar.vue tests/Feature/Applications/ApplicationReminderScanTest.php
git commit -m "$(cat <<'EOF'
feat: reminder scan + nav badge + rerun command

ApplicationReminderScan runs daily at site_settings-configured UTC
hour (gated every-minute pattern so the hour can be tuned without
scheduler restart). For each overdue non-terminal application, writes
a reminder event via EventWriter unless one was written today already
(same-day dedupe via whereDate).

HandleInertiaRequests shares reminderBadgeCount as a lazy prop,
cached in Redis per user for site_settings.nav_reminder_badge_ttl_seconds
(default 60s). Nav's Applications entry renders the count as a small
oxblood pill when > 0.

throughline:reminder-scan-now command runs the scan synchronously for
dogfood debugging.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: `RemotiveDriver`

**Files:**
- Create: `app/Services/Jobs/Drivers/RemotiveDriver.php`
- Modify: `config/throughline.php` (add `remotive` entry)
- Create: `tests/Fixtures/drivers/remotive/feed-happy.json`
- Create: `tests/Unit/Jobs/Drivers/RemotiveDriverTest.php`

Remotive's API returns `{"0-legal-notice": "...", "job-count": N, "jobs": [...]}`. Each job has `id`, `company_name`, `title`, `description` (HTML), `job_type`, `salary` (string, unstructured), `url`, `publication_date`, `candidate_required_location`.

- [ ] **Step 1: Register + fixture + test + impl**

Register in `config/throughline.php`:

```php
'remotive' => \App\Services\Jobs\Drivers\RemotiveDriver::class,
```

Fixture `tests/Fixtures/drivers/remotive/feed-happy.json`:

```json
{
  "0-legal-notice": "Use of Remotive.com API is subject to terms.",
  "job-count": 2,
  "jobs": [
    {
      "id": 1847392,
      "url": "https://remotive.com/remote-jobs/software-dev/senior-backend-1847392",
      "title": "Senior Backend Engineer",
      "company_name": "Acme Remote Co",
      "company_logo": null,
      "category": "Software Development",
      "tags": ["backend", "python", "aws"],
      "job_type": "full_time",
      "publication_date": "2026-04-18T09:00:00",
      "candidate_required_location": "Worldwide",
      "salary": "$140,000 - $180,000",
      "description": "<p>We are looking for...</p>"
    },
    {
      "id": 1847393,
      "url": "https://remotive.com/remote-jobs/software-dev/junior-1847393",
      "title": "Junior Full-Stack Developer",
      "company_name": "Beta Corp",
      "company_logo": null,
      "category": "Software Development",
      "tags": ["javascript"],
      "job_type": "full_time",
      "publication_date": "2026-04-19T11:00:00",
      "candidate_required_location": "Europe",
      "salary": "",
      "description": "<p>Entry-level role...</p>"
    }
  ]
}
```

Test `tests/Unit/Jobs/Drivers/RemotiveDriverTest.php` (4 tests — mirrors RemoteOK pattern from Plan 2):

```php
<?php

namespace Tests\Unit\Jobs\Drivers;

use App\Models\JobSource;
use App\Services\Jobs\Drivers\RemotiveDriver;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemotiveDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_yields_canonical_jobs(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/remotive/feed-happy.json'));
        Http::fake(['remotive.com/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'remotive', 'identifier' => null]);
        $jobs = iterator_to_array(app(RemotiveDriver::class)->fetch($source));

        $this->assertCount(2, $jobs);
        $this->assertSame('1847392', $jobs[0]->sourceId);
        $this->assertSame('Acme Remote Co', $jobs[0]->companyName);
        $this->assertSame('remote_only', $jobs[0]->remoteMode);
        $this->assertSame(140000, $jobs[0]->compMin);
        $this->assertSame(180000, $jobs[0]->compMax);
        $this->assertSame('USD', $jobs[0]->compCurrency);
    }

    public function test_empty_salary_preserved_as_null(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/drivers/remotive/feed-happy.json'));
        Http::fake(['remotive.com/*' => Http::response(json_decode($fixture, true))]);

        $source = JobSource::factory()->create(['source_type' => 'remotive', 'identifier' => null]);
        $jobs = iterator_to_array(app(RemotiveDriver::class)->fetch($source));

        $this->assertNull($jobs[1]->compMin);
        $this->assertNull($jobs[1]->compCurrency);
    }

    public function test_validateIdentifier_accepts_null(): void
    {
        $driver = app(RemotiveDriver::class);
        $this->assertTrue($driver->validateIdentifier(null));
        $this->assertTrue($driver->validateIdentifier(''));
    }

    public function test_http_failure_translates(): void
    {
        Http::fake(['remotive.com/*' => Http::response('', 500)]);

        $source = JobSource::factory()->create(['source_type' => 'remotive', 'identifier' => null]);
        $this->expectException(DriverFetchFailed::class);
        iterator_to_array(app(RemotiveDriver::class)->fetch($source));
    }
}
```

Impl `app/Services/Jobs/Drivers/RemotiveDriver.php`:

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

class RemotiveDriver implements JobSourceDriver
{
    public function sourceType(): string
    {
        return 'remotive';
    }

    public function validateIdentifier(?string $identifier): bool
    {
        return true; // Single-feed aggregator.
    }

    public function fetch(JobSource $source): Generator
    {
        try {
            $response = Http::acceptJson()->timeout(30)
                ->withHeaders(['User-Agent' => 'ThroughlineJobIngestor/1.0'])
                ->get('https://remotive.com/api/remote-jobs');
        } catch (\Throwable $e) {
            throw new DriverFetchFailed($this->sourceType(), null, 'HTTP error: ' . $e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw new DriverFetchFailed($this->sourceType(), null, "Upstream returned {$response->status()}");
        }

        foreach ($response->json('jobs') ?? [] as $raw) {
            yield $this->normalize($raw, $source);
        }
    }

    protected function normalize(array $raw, JobSource $source): CanonicalJobData
    {
        [$compMin, $compMax, $compCurrency] = $this->extractComp($raw['salary'] ?? '');

        return new CanonicalJobData(
            sourceId: (string) $raw['id'],
            sourceBoard: 'global',
            companyName: $raw['company_name'] ?? 'Unknown',
            title: $raw['title'] ?? 'Untitled',
            description: trim(strip_tags($raw['description'] ?? '')),
            locationRaw: $raw['candidate_required_location'] ?? null,
            locations: $raw['candidate_required_location'] ? [$raw['candidate_required_location']] : [],
            remoteMode: 'remote_only',
            compMin: $compMin,
            compMax: $compMax,
            compCurrency: $compCurrency,
            seniority: null,
            employmentType: $raw['job_type'] ?? null,
            applyUrl: $raw['url'] ?? '',
            postedAt: new DateTimeImmutable($raw['publication_date'] ?? 'now'),
            rawPayload: $raw,
        );
    }

    protected function extractComp(string $salary): array
    {
        if ($salary === '' || !preg_match('/\$?([\d,]+)(?:\s*-\s*\$?([\d,]+))?/', $salary, $m)) {
            return [null, null, null];
        }

        return [
            (int) str_replace(',', '', $m[1]),
            isset($m[2]) && $m[2] !== '' ? (int) str_replace(',', '', $m[2]) : null,
            'USD',
        ];
    }
}
```

Run, expect 4 pass. Full suite: 168 + 4 = 172.

- [ ] **Step 2: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Jobs/Drivers/RemotiveDriver.php config/throughline.php tests/Fixtures/drivers/remotive tests/Unit/Jobs/Drivers/RemotiveDriverTest.php
git commit -m "feat: RemotiveDriver — single-feed aggregator"
```

---

## Task 10: `USAJobsDriver` (paginated + authenticated)

**Files:**
- Create: `app/Services/Jobs/Drivers/USAJobsDriver.php`
- Modify: `config/throughline.php` (add `usajobs`)
- Modify: `config/services.php` (add `usajobs` block: auth_key, user_agent_email)
- Modify: `.env.example` (add `USAJOBS_AUTH_KEY=`, `USAJOBS_USER_AGENT_EMAIL=`)
- Create: `tests/Fixtures/drivers/usajobs/page1.json`
- Create: `tests/Fixtures/drivers/usajobs/page2-empty.json`
- Create: `tests/Unit/Jobs/Drivers/USAJobsDriverTest.php`

USAJobs API shape: `{"SearchResult": {"SearchResultCount": N, "SearchResultItems": [{"MatchedObjectId": "...", "MatchedObjectDescriptor": {...}}, ...]}}`. Requires `Authorization-Key` + `User-Agent` headers. Paginated via `Page=N` query param.

- [ ] **Step 1: Config + env**

Append to `config/services.php`:

```php
'usajobs' => [
    'auth_key' => env('USAJOBS_AUTH_KEY'),
    'user_agent_email' => env('USAJOBS_USER_AGENT_EMAIL'),
    'max_pages' => env('USAJOBS_MAX_PAGES', 10),
    'results_per_page' => env('USAJOBS_RESULTS_PER_PAGE', 25),
],
```

Append to `.env.example`:

```env
USAJOBS_AUTH_KEY=
USAJOBS_USER_AGENT_EMAIL=
```

Register in `config/throughline.php`:

```php
'usajobs' => \App\Services\Jobs\Drivers\USAJobsDriver::class,
```

- [ ] **Step 2: Fixtures**

`tests/Fixtures/drivers/usajobs/page1.json`:

```json
{
  "LanguageCode": "EN",
  "SearchResult": {
    "SearchResultCount": 2,
    "SearchResultCountAll": 2,
    "SearchResultItems": [
      {
        "MatchedObjectId": "834219200",
        "MatchedObjectDescriptor": {
          "PositionID": "LS-FH-25-12345-01",
          "PositionTitle": "Cybersecurity Specialist",
          "PositionURI": "https://www.usajobs.gov/job/834219200",
          "ApplyURI": ["https://www.usajobs.gov/job/834219200/apply"],
          "PositionLocationDisplay": "Washington, District of Columbia, US",
          "PositionLocation": [{"LocationName": "Washington, District of Columbia, US"}],
          "OrganizationName": "U.S. Cyber Command",
          "PositionRemuneration": [{"MinimumRange": "112015", "MaximumRange": "145617", "RateIntervalCode": "PA", "Description": "Per Year"}],
          "PositionStartDate": "2026-04-15",
          "PositionEndDate": "2026-04-30",
          "PublicationStartDate": "2026-04-15T00:00:00.000",
          "QualificationSummary": "Must hold an active TS/SCI clearance...",
          "UserArea": {"Details": {"JobSummary": "Defend critical DoD networks..."}}
        }
      },
      {
        "MatchedObjectId": "834219201",
        "MatchedObjectDescriptor": {
          "PositionID": "NH-FH-25-67890-02",
          "PositionTitle": "Software Developer",
          "PositionURI": "https://www.usajobs.gov/job/834219201",
          "ApplyURI": ["https://www.usajobs.gov/job/834219201/apply"],
          "PositionLocationDisplay": "Remote",
          "PositionLocation": [{"LocationName": "Remote"}],
          "OrganizationName": "Department of Veterans Affairs",
          "PositionRemuneration": [],
          "PositionStartDate": "2026-04-16",
          "PositionEndDate": "2026-05-01",
          "PublicationStartDate": "2026-04-16T00:00:00.000",
          "QualificationSummary": "",
          "UserArea": {"Details": {"JobSummary": "Remote full-stack development..."}}
        }
      }
    ]
  }
}
```

`tests/Fixtures/drivers/usajobs/page2-empty.json`:

```json
{
  "LanguageCode": "EN",
  "SearchResult": {
    "SearchResultCount": 0,
    "SearchResultItems": []
  }
}
```

- [ ] **Step 3: Tests**

`tests/Unit/Jobs/Drivers/USAJobsDriverTest.php`:

```php
<?php

namespace Tests\Unit\Jobs\Drivers;

use App\Models\JobSource;
use App\Services\Jobs\Drivers\USAJobsDriver;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class USAJobsDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.usajobs.auth_key' => 'test-key',
            'services.usajobs.user_agent_email' => 'test@throughline.test',
        ]);
    }

    public function test_yields_canonical_jobs(): void
    {
        $page1 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/usajobs/page1.json')), true);
        $page2 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/usajobs/page2-empty.json')), true);

        Http::fake([
            'data.usajobs.gov/api/search*Page=1*' => Http::response($page1),
            'data.usajobs.gov/api/search*Page=2*' => Http::response($page2),
        ]);

        $source = JobSource::factory()->create(['source_type' => 'usajobs', 'identifier' => null]);
        $jobs = iterator_to_array(app(USAJobsDriver::class)->fetch($source));

        $this->assertCount(2, $jobs);
        $this->assertSame('834219200', $jobs[0]->sourceId);
        $this->assertSame('U.S. Cyber Command', $jobs[0]->companyName);
        $this->assertSame('Cybersecurity Specialist', $jobs[0]->title);
        $this->assertSame(112015, $jobs[0]->compMin);
        $this->assertSame(145617, $jobs[0]->compMax);
        $this->assertSame('USD', $jobs[0]->compCurrency);
    }

    public function test_remote_location_produces_remote_only(): void
    {
        $page1 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/usajobs/page1.json')), true);
        $page2 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/usajobs/page2-empty.json')), true);

        Http::fake([
            'data.usajobs.gov/api/search*Page=1*' => Http::response($page1),
            'data.usajobs.gov/api/search*Page=2*' => Http::response($page2),
        ]);

        $source = JobSource::factory()->create(['source_type' => 'usajobs', 'identifier' => null]);
        $jobs = iterator_to_array(app(USAJobsDriver::class)->fetch($source));

        $this->assertSame('remote_only', $jobs[1]->remoteMode);
    }

    public function test_stops_paginating_on_empty_results(): void
    {
        $page1 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/usajobs/page1.json')), true);
        $page2 = json_decode(file_get_contents(base_path('tests/Fixtures/drivers/usajobs/page2-empty.json')), true);

        $callCount = 0;
        Http::fake(function ($request) use ($page1, $page2, &$callCount) {
            $callCount++;
            if (str_contains($request->url(), 'Page=1')) {
                return Http::response($page1);
            }
            return Http::response($page2);
        });

        $source = JobSource::factory()->create(['source_type' => 'usajobs', 'identifier' => null]);
        iterator_to_array(app(USAJobsDriver::class)->fetch($source));

        $this->assertSame(2, $callCount);
    }

    public function test_missing_credentials_throws_driver_fetch_failed(): void
    {
        config(['services.usajobs.auth_key' => null]);

        $source = JobSource::factory()->create(['source_type' => 'usajobs', 'identifier' => null]);
        $this->expectException(DriverFetchFailed::class);
        iterator_to_array(app(USAJobsDriver::class)->fetch($source));
    }

    public function test_http_failure_translates(): void
    {
        Http::fake(['data.usajobs.gov/*' => Http::response('', 503)]);

        $source = JobSource::factory()->create(['source_type' => 'usajobs', 'identifier' => null]);
        $this->expectException(DriverFetchFailed::class);
        iterator_to_array(app(USAJobsDriver::class)->fetch($source));
    }
}
```

- [ ] **Step 4: Implementation**

`app/Services/Jobs/Drivers/USAJobsDriver.php`:

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

class USAJobsDriver implements JobSourceDriver
{
    public function sourceType(): string
    {
        return 'usajobs';
    }

    public function validateIdentifier(?string $identifier): bool
    {
        return true; // Single-feed API.
    }

    public function fetch(JobSource $source): Generator
    {
        $authKey = config('services.usajobs.auth_key');
        $userAgentEmail = config('services.usajobs.user_agent_email');

        if (!$authKey || !$userAgentEmail) {
            throw new DriverFetchFailed($this->sourceType(), null, 'USAJobs credentials not configured (USAJOBS_AUTH_KEY, USAJOBS_USER_AGENT_EMAIL)');
        }

        $maxPages = (int) config('services.usajobs.max_pages', 10);
        $perPage = (int) config('services.usajobs.results_per_page', 25);

        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $response = Http::acceptJson()->timeout(30)->withHeaders([
                    'Authorization-Key' => $authKey,
                    'User-Agent' => "ThroughlineJobIngestor/1.0 ({$userAgentEmail})",
                    'Host' => 'data.usajobs.gov',
                ])->get('https://data.usajobs.gov/api/search', [
                    'Page' => $page,
                    'ResultsPerPage' => $perPage,
                ]);
            } catch (\Throwable $e) {
                throw new DriverFetchFailed($this->sourceType(), null, 'HTTP error: ' . $e->getMessage(), $e);
            }

            if ($response->failed()) {
                throw new DriverFetchFailed($this->sourceType(), null, "Upstream returned {$response->status()} on page {$page}");
            }

            $items = $response->json('SearchResult.SearchResultItems') ?? [];
            if (empty($items)) {
                return;
            }

            foreach ($items as $item) {
                yield $this->normalize($item, $source);
            }
        }
    }

    protected function normalize(array $item, JobSource $source): CanonicalJobData
    {
        $desc = $item['MatchedObjectDescriptor'] ?? [];
        [$compMin, $compMax] = $this->extractComp($desc['PositionRemuneration'] ?? []);
        $locationRaw = $desc['PositionLocationDisplay'] ?? null;

        $remote = $locationRaw && stripos($locationRaw, 'remote') !== false ? 'remote_only' : 'onsite';

        $description = trim(
            ($desc['UserArea']['Details']['JobSummary'] ?? '') . "\n\n" .
            ($desc['QualificationSummary'] ?? ''),
        );

        return new CanonicalJobData(
            sourceId: (string) ($item['MatchedObjectId'] ?? ''),
            sourceBoard: 'federal',
            companyName: $desc['OrganizationName'] ?? 'U.S. Federal Government',
            title: $desc['PositionTitle'] ?? 'Untitled',
            description: $description,
            locationRaw: $locationRaw,
            locations: $locationRaw ? [$locationRaw] : [],
            remoteMode: $remote,
            compMin: $compMin,
            compMax: $compMax,
            compCurrency: $compMin !== null ? 'USD' : null,
            seniority: null,
            employmentType: null,
            applyUrl: $desc['ApplyURI'][0] ?? ($desc['PositionURI'] ?? ''),
            postedAt: new DateTimeImmutable($desc['PublicationStartDate'] ?? 'now'),
            rawPayload: $item,
        );
    }

    protected function extractComp(array $remuneration): array
    {
        foreach ($remuneration as $entry) {
            if (!empty($entry['MinimumRange'])) {
                return [
                    (int) $entry['MinimumRange'],
                    !empty($entry['MaximumRange']) ? (int) $entry['MaximumRange'] : null,
                ];
            }
        }
        return [null, null];
    }
}
```

- [ ] **Step 5: Run + commit**

Expected: 5 pass. Full suite: 172 + 5 = 177.

```bash
vendor/bin/pint --dirty
git add app/Services/Jobs/Drivers/USAJobsDriver.php config/throughline.php config/services.php .env.example tests/Fixtures/drivers/usajobs tests/Unit/Jobs/Drivers/USAJobsDriverTest.php
git commit -m "$(cat <<'EOF'
feat: USAJobsDriver completes the Plan-2-starter-set of 8 drivers

USAJobs API returns SearchResult.SearchResultItems with nested
MatchedObjectDescriptor. Driver paginates via Page query param until
results are empty or max_pages (default 10 × 25 results/page = 250
cap). Authorization-Key + User-Agent with email are required headers;
missing credentials throw DriverFetchFailed before any HTTP call.

Remote mode inferred from PositionLocationDisplay containing 'remote'
(case-insensitive). Compensation pulled from PositionRemuneration
array's first entry with a MinimumRange. Description concatenates
JobSummary + QualificationSummary.

Plan 2 shipped Greenhouse + Lever + RemoteOK. Plan 3 shipped Workable
+ Ashby + Adzuna. Plan 4 completes the starter set with Remotive +
USAJobs.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: E2E + docs + final verification

**Files:**
- Modify: `tests/e2e/feed-happy-path.spec.ts` (extend with save-from-feed + application-list assertion)
- Modify: `CLAUDE.md` (Plan 4 architecture bullets + rerun command)
- Modify: `README.md` (What's here now / Plan 4)

- [ ] **Step 1: Extend Playwright spec**

After the existing Plan 3 stage-2 assertions, add:

```ts
// Save the first card.
await page.locator('article').first().getByRole('button', { name: 'Save' }).click();
await expect(page.locator('article').first().getByText('Saved')).toBeVisible({ timeout: 5000 });

// Navigate to applications and confirm the saved job shows in the saved column.
await page.goto('/applications');
await expect(page.getByText('Applications')).toBeVisible();

// The saved column should contain exactly one ApplicationCard matching the job we saved.
const savedColumn = page.locator('div:has(> header:has(h2:text("Saved"))) ul').first();
await expect(savedColumn.locator('a').first()).toBeVisible();
```

- [ ] **Step 2: CLAUDE.md updates**

Under "Architecture notes", append:

```markdown
- **Application pipeline** — `applications` table with 8-status state machine; `application_events` is append-only (the model's `booted()` updating-listener throws LogicException). `EventWriter` service is the only writer — call `$eventWriter->logStatusChange()` or equivalent from inside a DB transaction.
- **Save-from-feed** preserves the stage-2 rerank output at save-time into `applications.stage2_snapshot`, so later reranks can't retcon user history.
- **Kanban** uses `vuedraggable`; each drop is a single PATCH to `/applications/{id}` with `{status: 'newstatus'}`.
- **Reminder scan** (`ApplicationReminderScan`) runs daily at `site_settings.reminder_scan_hour_utc` (default 09:00 UTC). Writes a `reminder` event for overdue non-terminal applications once per day. Nav badge count is cached in Redis per user for `nav_reminder_badge_ttl_seconds` (default 60).
- **Drivers (Plan 4)** — `RemotiveDriver` (single-feed aggregator) + `USAJobsDriver` (paginated + authenticated). Plan 2–4 complete the main product spec's 8-driver starter set: Greenhouse, Lever, RemoteOK, Workable, Ashby, Adzuna, Remotive, USAJobs.
```

Under "Key commands", append:

```markdown
- `php artisan throughline:reminder-scan-now` — run the application reminder scan synchronously (for dogfood debugging).
```

Update "What's NOT here yet" to remove Plan 4 items now shipped.

- [ ] **Step 3: README.md updates**

Under "What's here now" (or equivalent), add:

- Save-from-feed action writes an application with stage-2 snapshot
- Kanban UI with drag-and-drop status transitions
- Application detail: notes, contacts, documents, event timeline
- Daily reminder scan with nav-badge surfacing
- Remotive + USAJobs drivers complete the starter set

- [ ] **Step 4: Full verification**

```bash
/c/Users/david/.config/herd/bin/php84/php.exe artisan test --compact
```

Expected: 177 pass (baseline 134 + Plan 4 additions: 9 + 4 + 5 + 5 + 5 + 4 + 2 + 4 + 5 = ~43).

```bash
npm run test:e2e 2>&1 | tail -20
```

Expected: happy-path spec green including new save-to-kanban assertions.

```bash
npm run lint
```

Expected: clean.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add tests/e2e/feed-happy-path.spec.ts CLAUDE.md README.md
git commit -m "$(cat <<'EOF'
feat: Playwright spec asserts save-to-kanban + docs for Plan 4

Playwright happy-path extended to click Save on the first feed card,
assert the Saved indicator appears, navigate to /applications, and
confirm the saved job shows in the Saved kanban column.

CLAUDE.md gains five new architecture bullets (pipeline, save-from-
feed snapshot, kanban drag, reminder scan, Plan 4 drivers) and the
throughline:reminder-scan-now command. README updated with the new
Plan 4 feature list.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Done

Plan 4 is complete when all 11 tasks are green, the full PHP suite reports ~177 tests passing, and the Playwright happy path ends in the applications kanban. Plan 5 starts with the admin dashboard: overview cards, user impersonation, analytics with AI cost charts (joining `ai_usage_events.context_id` to search_profile_id to user_id — enabled by Plan 3 Task 2's context_id fix), error log, audit log viewer, Horizon mount.
