<?php

namespace App\Http\Requests\Applications;

use App\Models\ApplicationContact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && (int) $this->route('application')->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'role' => ['required', Rule::in(ApplicationContact::ROLES)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
