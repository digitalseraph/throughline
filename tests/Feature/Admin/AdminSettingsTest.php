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
        SiteSetting::create(['key' => 'site_name', 'value' => 'Throughline', 'type' => 'string', 'description' => '']);
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
