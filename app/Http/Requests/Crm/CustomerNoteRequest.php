<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class CustomerNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'content' => $this->filled('content') ? trim((string) $this->input('content')) : $this->input('content'),
        ]);
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return ['content' => __('crm.fields.content')];
    }
}
