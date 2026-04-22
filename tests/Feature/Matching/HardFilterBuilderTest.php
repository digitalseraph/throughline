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
        CanonicalJob::factory()->create(['comp_min' => null, 'comp_currency' => null, 'title' => 'nullcomp']);

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
