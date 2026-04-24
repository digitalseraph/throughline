<?php

namespace Tests\Feature\Console;

use App\Mail\CostAlertMail;
use App\Models\InfrastructureCost;
use App\Models\InfrastructureCostSnapshot;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CostsCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteSetting::firstOrCreate(
            ['key' => 'cost_alert_threshold_usd'],
            ['value' => '100.00', 'type' => 'string', 'description' => ''],
        );
        SiteSetting::firstOrCreate(
            ['key' => 'cost_alert_last_notified_month'],
            ['value' => '', 'type' => 'string', 'description' => ''],
        );
    }

    public function test_close_month_writes_snapshot_for_previous_month_by_default(): void
    {
        InfrastructureCost::create(['name' => 'Droplet', 'amount_usd' => 18, 'billing_cycle' => 'monthly', 'is_active' => true]);

        $this->artisan('costs:close-month')->assertExitCode(0);

        $snap = InfrastructureCostSnapshot::first();
        $this->assertNotNull($snap);
        $this->assertSame(now()->subMonth()->startOfMonth()->toDateString(), $snap->snapshot_month->toDateString());
    }

    public function test_close_month_accepts_explicit_month_option(): void
    {
        InfrastructureCost::create(['name' => 'Droplet', 'amount_usd' => 18, 'billing_cycle' => 'monthly', 'is_active' => true]);

        $this->artisan('costs:close-month', ['--month' => '2026-01'])->assertExitCode(0);

        $this->assertDatabaseHas('infrastructure_cost_snapshots', ['snapshot_month' => '2026-01-01']);
    }

    public function test_check_alert_sends_mail_to_admins_when_threshold_exceeded(): void
    {
        Mail::fake();

        InfrastructureCost::create(['name' => 'Expensive', 'amount_usd' => 500, 'billing_cycle' => 'monthly', 'is_active' => true]);
        User::factory()->create(['role' => 'admin', 'email' => 'admin@example.com', 'notification_email_enabled' => true]);
        User::factory()->create(['role' => 'user', 'email' => 'user@example.com', 'notification_email_enabled' => true]);

        $this->artisan('costs:check-alert')->assertExitCode(0);

        Mail::assertQueued(CostAlertMail::class, 1);
        Mail::assertQueued(
            CostAlertMail::class,
            fn (CostAlertMail $mail) => $mail->admin->email === 'admin@example.com',
        );
    }

    public function test_check_alert_does_not_double_fire_in_same_month(): void
    {
        Mail::fake();

        InfrastructureCost::create(['name' => 'Expensive', 'amount_usd' => 500, 'billing_cycle' => 'monthly', 'is_active' => true]);
        User::factory()->create(['role' => 'admin', 'notification_email_enabled' => true]);

        $this->artisan('costs:check-alert');
        $this->artisan('costs:check-alert');

        Mail::assertQueued(CostAlertMail::class, 1);
    }

    public function test_check_alert_skips_opted_out_admins(): void
    {
        Mail::fake();

        InfrastructureCost::create(['name' => 'Expensive', 'amount_usd' => 500, 'billing_cycle' => 'monthly', 'is_active' => true]);
        User::factory()->create(['role' => 'admin', 'notification_email_enabled' => false]);

        $this->artisan('costs:check-alert');
        Mail::assertNothingQueued();
    }
}
