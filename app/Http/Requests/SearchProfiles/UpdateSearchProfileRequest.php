<?php

namespace App\Http\Requests\SearchProfiles;

class UpdateSearchProfileRequest extends StoreSearchProfileRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && (int) $this->route('profile')->user_id === $this->user()->id;
    }
}
