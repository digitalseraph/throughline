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
        $admin = new User; // unsaved, has no id
        $admin->id = 999999; // nonexistent; FK will reject

        // Should NOT throw — should log a warning and return null.
        $result = app(AdminLogger::class)->record($admin, 'will.fail');
        $this->assertNull($result);
        $this->assertSame(0, AdminActivityLog::count());
    }
}
