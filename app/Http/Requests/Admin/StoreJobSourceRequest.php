<?php

namespace App\Http\Requests\Admin;

use App\Services\Jobs\DriverRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        $registry = app(DriverRegistry::class);

        return [
            'source_type' => ['required', Rule::in($registry->supportedTypes())],
            'identifier' => ['nullable', 'string', 'max:120'],
            'display_name' => ['required', 'string', 'max:160'],
            'fetch_cadence_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'is_enabled' => ['boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $registry = app(DriverRegistry::class);
            $driver = $registry->driverFor($this->input('source_type'));
            if (! $driver->validateIdentifier($this->input('identifier'))) {
                $v->errors()->add('identifier', 'Identifier is required for this source type.');
            }
        });
    }
}
