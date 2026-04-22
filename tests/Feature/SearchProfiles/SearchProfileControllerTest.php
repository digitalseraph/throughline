<?php

namespace Tests\Feature\SearchProfiles;

use App\Models\Resume;
use App\Models\SearchProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_profile_with_and_without_resume(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();

        $this->actingAs($user)->post(route('search-profiles.store'), [
            'name' => 'Staff frontend',
            'resume_id' => $resume->id,
            'role_keywords' => ['frontend', 'react'],
            'locations' => ['NYC'],
            'remote_mode' => 'remote_only',
            'comp_min' => 180000,
            'comp_currency' => 'USD',
            'industries' => ['fintech'],
            'seniority_levels' => ['staff', 'principal'],
            'excluded_companies' => ['BigCo'],
            'is_active' => true,
        ])->assertRedirect();

        $profile = SearchProfile::first();
        $this->assertSame($resume->id, $profile->resume_id);
        $this->assertSame(['frontend', 'react'], $profile->role_keywords);

        $this->actingAs($user)->post(route('search-profiles.store'), [
            'name' => 'Broad search',
            'resume_id' => null,
            'remote_mode' => 'any',
        ])->assertRedirect();

        $this->assertSame(2, SearchProfile::count());
    }

    public function test_deleting_resume_nulls_out_profile_resume_id(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create();
        $profile = SearchProfile::factory()->for($user)->create(['resume_id' => $resume->id]);

        $resume->delete();

        $this->assertNull($profile->fresh()->resume_id);
    }

    public function test_update_rejects_foreign_user(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $profile = SearchProfile::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->put(route('search-profiles.update', $profile), ['name' => 'Hacked', 'remote_mode' => 'any'])
            ->assertForbidden();
    }

    public function test_validation_rejects_unknown_remote_mode(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('search-profiles.store'), ['name' => 'x', 'remote_mode' => 'bogus'])
            ->assertSessionHasErrors('remote_mode');
    }
}
