<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InteractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'content' => $this->filled('content') ? trim((string) $this->input('content')) : null,
            'occurred_at' => $this->filled('occurred_at') ? $this->input('occurred_at') : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['call', 'email', 'sms', 'meeting', 'note'])],
            'content' => ['nullable', 'string', 'max:5000'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }

    public function attributes(): array
    {
        return [
            'type' => __('crm.fields.type'),
            'content' => __('crm.fields.content'),
            'occurred_at' => __('crm.fields.occurred_at'),
        ];
    }
}
