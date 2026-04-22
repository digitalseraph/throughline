<?php

namespace App\Http\Requests\Applications;

use App\Models\ApplicationDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && (int) $this->route('application')->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(ApplicationDocument::KINDS)],
            'file' => ['required', 'file', 'mimetypes:application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/png,image/jpeg,text/plain', 'max:20480'],
        ];
    }
}
