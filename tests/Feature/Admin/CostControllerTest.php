<?php

namespace Tests\Feature\Admin;

use App\Models\AdminActivityLog;
use App\Models\InfrastructureCost;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CostControllerTest extends TestCase
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

    public function test_non_admin_cannot_view_costs_page(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->actingAs($user)->get(route('admin.costs.index'))->assertForbidden();
    }

    public function test_admin_can_view_costs_page_with_line_items_and_totals(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        InfrastructureCost::create([
            'name' => 'DigitalOcean Droplet',
            'category' => 'Hosting',
            'amount_usd' => 18.00,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);
        InfrastructureCost::create([
            'name' => 'Annual domain',
            'category' => 'DNS',
            'amount_usd' => 12.00,
            'billing_cycle' => 'annual',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.costs.index'));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(2, $props['lineItems']);
        // 18 + (12 / 12) = 19
        $this->assertEqualsWithDelta(19.00, $props['fixedMonthlyUsd'], 0.01);
    }

    public function test_admin_can_create_a_line_item(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('admin.costs.store'), [
                'name' => 'Postmark',
                'category' => 'Email',
                'amount_usd' => 15.00,
                'billing_cycle' => 'monthly',
                'notes' => '10k emails/mo tier',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('infrastructure_costs', ['name' => 'Postmark', 'amount_usd' => 15.00]);
        $this->assertSame(1, AdminActivityLog::where('action', 'cost.created')->count());
    }

    public function test_admin_can_update_a_line_item(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cost = InfrastructureCost::create([
            'name' => 'DO Droplet',
            'amount_usd' => 18.00,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.costs.update', $cost), [
                'name' => 'DO Droplet',
                'amount_usd' => 24.00,
                'billing_cycle' => 'monthly',
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertSame('24.00', (string) $cost->fresh()->amount_usd);
        $this->assertSame(1, AdminActivityLog::where('action', 'cost.updated')->count());
    }

    public function test_admin_can_delete_a_line_item(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cost = InfrastructureCost::create([
            'name' => 'Retired service',
            'amount_usd' => 5.00,
            'billing_cycle' => 'monthly',
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.costs.destroy', $cost))
            ->assertRedirect();

        $this->assertDatabaseMissing('infrastructure_costs', ['id' => $cost->id]);
        $this->assertSame(1, AdminActivityLog::where('action', 'cost.deleted')->count());
    }

    public function test_admin_can_update_threshold(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->patch(route('admin.costs.threshold'), ['threshold_usd' => 250])
            ->assertRedirect();

        $this->assertSame('250.00', SiteSetting::where('key', 'cost_alert_threshold_usd')->value('value'));
        $this->assertSame(1, AdminActivityLog::where('action', 'cost.threshold.updated')->count());
    }
}
