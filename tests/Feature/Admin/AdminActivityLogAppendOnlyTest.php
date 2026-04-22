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
