<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActivationRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'feature' => ['required', Rule::in(['crm', 'forms', 'smart_ivr'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
