<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class IntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:190'],
            'is_active' => ['nullable', 'boolean'],
            'config_text' => ['nullable', 'string'],
        ];
    }
}
