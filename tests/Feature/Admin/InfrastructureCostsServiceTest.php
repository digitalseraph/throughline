<?php

namespace Tests\Feature\Admin;

use App\Models\AiUsageEvent;
use App\Models\InfrastructureCost;
use App\Models\InfrastructureCostSnapshot;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Admin\InfrastructureCosts;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InfrastructureCostsServiceTest extends TestCase
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

    public function test_monthly_amount_normalizes_annual_to_twelfth(): void
    {
        $cost = InfrastructureCost::create([
            'name' => 'Annual',
            'amount_usd' => 120.00,
            'billing_cycle' => 'annual',
            'is_active' => true,
        ]);
        $this->assertEqualsWithDelta(10.00, $cost->monthlyAmount(), 0.001);
    }

    public function test_fixed_monthly_total_sums_only_active_items(): void
    {
        InfrastructureCost::create(['name' => 'A', 'amount_usd' => 18, 'billing_cycle' => 'monthly', 'is_active' => true]);
        InfrastructureCost::create(['name' => 'B', 'amount_usd' => 12, 'billing_cycle' => 'annual',  'is_active' => true]);
        InfrastructureCost::create(['name' => 'C', 'amount_usd' => 50, 'billing_cycle' => 'monthly', 'is_active' => false]);

        $service = app(InfrastructureCosts::class);
        $this->assertEqualsWithDelta(19.00, $service->fixedMonthlyTotalUsd(), 0.01);
    }

    public function test_ai_spend_for_month_filters_by_window(): void
    {
        $user = User::factory()->create();
        AiUsageEvent::create([
            'user_id' => $user->id,
            'provider' => 'openai',
            'operation' => 'embed',
            'model' => 'text-embedding-3-small',
            'input_tokens' => 100,
            'output_tokens' => 0,
            'estimated_cost_usd' => 3.50,
            'created_at' => CarbonImmutable::now()->startOfMonth()->addDays(5),
        ]);
        AiUsageEvent::create([
            'user_id' => $user->id,
            'provider' => 'anthropic',
            'operation' => 'rerank',
            'model' => 'claude-sonnet-4-6',
            'input_tokens' => 100,
            'output_tokens' => 100,
            'estimated_cost_usd' => 99.99,
            'created_at' => CarbonImmutable::now()->subMonth()->startOfMonth()->addDays(10),
        ]);

        $service = app(InfrastructureCosts::class);
        $this->assertEqualsWithDelta(3.50, $service->aiSpendForMonth(CarbonImmutable::now()), 0.01);
        $this->assertEqualsWithDelta(99.99, $service->aiSpendForMonth(CarbonImmutable::now()->subMonth()), 0.01);
    }

    public function test_record_snapshot_is_idempotent(): void
    {
        InfrastructureCost::create(['name' => 'Droplet', 'amount_usd' => 18, 'billing_cycle' => 'monthly', 'is_active' => true]);
        $service = app(InfrastructureCosts::class);
        $month = CarbonImmutable::now()->startOfMonth();

        $service->recordSnapshotForMonth($month);
        $service->recordSnapshotForMonth($month);

        $this->assertSame(1, InfrastructureCostSnapshot::count());
        $snap = InfrastructureCostSnapshot::first();
        $this->assertEqualsWithDelta(18.00, (float) $snap->fixed_costs_usd, 0.01);
    }

    public function test_maybe_fire_alert_returns_fired_true_once_per_month(): void
    {
        InfrastructureCost::create(['name' => 'Expensive', 'amount_usd' => 150, 'billing_cycle' => 'monthly', 'is_active' => true]);

        $service = app(InfrastructureCosts::class);
        $first = $service->maybeFireAlert();
        $second = $service->maybeFireAlert();

        $this->assertTrue($first['fired']);
        $this->assertFalse($second['fired']);
        $this->assertSame('already alerted this month', $second['reason']);
    }

    public function test_maybe_fire_alert_does_not_fire_under_threshold(): void
    {
        InfrastructureCost::create(['name' => 'Cheap', 'amount_usd' => 10, 'billing_cycle' => 'monthly', 'is_active' => true]);

        $result = app(InfrastructureCosts::class)->maybeFireAlert();
        $this->assertFalse($result['fired']);
        $this->assertSame('under threshold', $result['reason']);
    }

    public function test_maybe_fire_alert_respects_zero_threshold_as_disabled(): void
    {
        app(\App\Services\SiteSettings::class)->set('cost_alert_threshold_usd', '0');
        InfrastructureCost::create(['name' => 'Expensive', 'amount_usd' => 5000, 'billing_cycle' => 'monthly', 'is_active' => true]);

        $result = app(InfrastructureCosts::class)->maybeFireAlert();
        $this->assertFalse($result['fired']);
        $this->assertSame('threshold disabled', $result['reason']);
    }
}
