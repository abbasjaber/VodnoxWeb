<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'exists:organizations,id'],
            'plan_id' => ['nullable', 'exists:plans,id'],
            'status' => ['required', Rule::in(['pending', 'active', 'trial', 'suspended', 'expired'])],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:8'],
        ];
    }

    public function messages(): array
    {
        return ['ends_at.after_or_equal' => __('admin.val_ends_after')];
    }
}
