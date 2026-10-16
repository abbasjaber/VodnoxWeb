<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:190'],
            'name_en' => ['required', 'string', 'max:190'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'monthly_price' => ['nullable', 'numeric', 'min:0'],
            'yearly_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:8'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'limits' => ['nullable', 'array'],
            'limits.users' => ['nullable', 'integer', 'min:0'],
            'limits.extensions' => ['nullable', 'integer', 'min:0'],
            'limits.concurrent_calls' => ['nullable', 'integer', 'min:0'],
            'limits.recording_storage' => ['nullable', 'integer', 'min:0'],
            'limits.phone_numbers' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
