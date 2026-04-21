<?php

namespace App\Http\Requests\SearchProfiles;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSearchProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'resume_id' => [
                'nullable', 'integer',
                Rule::exists('resumes', 'id')->where('user_id', $this->user()->id),
            ],
            'role_keywords' => ['array'],
            'role_keywords.*' => ['string', 'max:60'],
            'locations' => ['array'],
            'locations.*' => ['string', 'max:80'],
            'remote_mode' => ['required', Rule::in(['any', 'remote_only', 'hybrid_ok', 'onsite'])],
            'comp_min' => ['nullable', 'integer', 'min:0'],
            'comp_currency' => ['nullable', 'string', 'size:3'],
            'industries' => ['array'],
            'industries.*' => ['string', 'max:60'],
            'seniority_levels' => ['array'],
            'seniority_levels.*' => ['string', 'max:40'],
            'excluded_companies' => ['array'],
            'excluded_companies.*' => ['string', 'max:120'],
            'is_active' => ['boolean'],
        ];
    }
}
