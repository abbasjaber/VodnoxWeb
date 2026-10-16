<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => $this->filled('title') ? trim((string) $this->input('title')) : $this->input('title'),
            'description' => $this->filled('description') ? trim((string) $this->input('description')) : null,
            'customer_id' => $this->filled('customer_id') ? $this->input('customer_id') : null,
            'opportunity_id' => $this->filled('opportunity_id') ? $this->input('opportunity_id') : null,
            'assigned_to' => $this->filled('assigned_to') ? $this->input('assigned_to') : null,
            'due_at' => $this->filled('due_at') ? $this->input('due_at') : null,
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
            'opportunity_id' => [
                'nullable', 'integer',
                Rule::exists('opportunities', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'assigned_to' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId)->where('active', true)),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['pending', 'completed', 'cancelled'])],
            'priority' => ['required', Rule::in(['low', 'medium', 'high'])],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.exists' => __('crm.validation.customer_in_org'),
            'opportunity_id.exists' => __('crm.validation.opportunity_in_org'),
            'assigned_to.exists' => __('crm.validation.assigned_active'),
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_id' => __('crm.fields.customer'),
            'opportunity_id' => __('crm.fields.opportunity'),
            'assigned_to' => __('crm.fields.assigned_to'),
            'title' => __('crm.fields.title'),
            'description' => __('crm.fields.description'),
            'due_at' => __('crm.fields.due_at'),
            'status' => __('crm.fields.status'),
            'priority' => __('crm.fields.priority'),
        ];
    }
}
