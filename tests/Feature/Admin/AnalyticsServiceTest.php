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

        $this->assertGreaterThan(0, $userOnly['datasets'][0]['data'][6]);
        $this->assertEquals(0.5, $userOnly['datasets'][0]['data'][6]);
        $this->assertEquals(1.5, $global['datasets'][0]['data'][6]);
    }
}
