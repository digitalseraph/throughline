<?php

namespace App\Http\Controllers\Admin;

use App\Models\SiteSetting;
use App\Services\SiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends BaseController
{
    protected array $envSecretKeys = [
        'OPENAI_API_KEY' => 'services.openai.api_key',
        'ANTHROPIC_API_KEY' => 'services.anthropic.api_key',
        'ADZUNA_APP_ID' => 'services.adzuna.app_id',
        'ADZUNA_APP_KEY' => 'services.adzuna.app_key',
        'USAJOBS_AUTH_KEY' => 'services.usajobs.auth_key',
    ];

    public function index(): Response
    {
        $settings = SiteSetting::orderBy('key')->get(['key', 'value', 'type', 'description']);

        $secrets = [];
        foreach ($this->envSecretKeys as $label => $configKey) {
            $value = (string) config($configKey, '');
            $secrets[] = [
                'key' => $label,
                'masked' => $this->mask($value),
                'is_set' => $value !== '',
            ];
        }

        return Inertia::render('admin/Settings', [
            'settings' => $settings,
            'secrets' => $secrets,
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $setting = SiteSetting::where('key', $key)->first();
        abort_unless($setting, 404);

        $rules = match ($setting->type) {
            'int' => ['required', 'integer'],
            'bool' => ['required', 'in:true,false'],
            'json' => ['required', 'json'],
            default => ['required', 'string'],
        };
        $data = $request->validate(['value' => $rules]);

        $old = $setting->value;
        $setting->value = (string) $data['value'];
        $setting->save();

        app(SiteSettings::class)->invalidate();

        $this->audit('site_setting.updated', $setting, ['key' => $key, 'old' => $old, 'new' => $setting->value]);

        return redirect()->back()->with('status', 'Setting updated.');
    }

    protected function mask(string $value): string
    {
        if ($value === '') {
            return '(not set)';
        }
        if (strlen($value) <= 8) {
            return str_repeat('•', strlen($value));
        }

        return substr($value, 0, 4).'…'.substr($value, -4);
    }
}
