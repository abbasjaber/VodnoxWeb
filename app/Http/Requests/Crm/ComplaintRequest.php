<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'subject' => $this->filled('subject') ? trim((string) $this->input('subject')) : $this->input('subject'),
            'description' => $this->filled('description') ? trim((string) $this->input('description')) : $this->input('description'),
            'customer_id' => $this->filled('customer_id') ? $this->input('customer_id') : null,
            'department_id' => $this->filled('department_id') ? $this->input('department_id') : null,
            'assigned_to' => $this->filled('assigned_to') ? $this->input('assigned_to') : null,
        ]);
    }

    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;

        return [
            'customer_id' => [
                'nullable', 'integer',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'department_id' => [
                'nullable', 'integer',
                Rule::exists('departments', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'assigned_to' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId)->where('active', true)),
            ],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high'])],
            'status' => ['required', Rule::in(['open', 'in_progress', 'resolved', 'closed'])],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.exists' => __('crm.validation.customer_in_org'),
            'department_id.exists' => __('crm.validation.department_in_org'),
            'assigned_to.exists' => __('crm.validation.assigned_active'),
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_id' => __('crm.fields.customer'),
            'department_id' => __('crm.fields.department'),
            'assigned_to' => __('crm.fields.assigned_to'),
            'subject' => __('crm.fields.subject'),
            'description' => __('crm.fields.description'),
            'priority' => __('crm.fields.priority'),
            'status' => __('crm.fields.status'),
        ];
    }
}
