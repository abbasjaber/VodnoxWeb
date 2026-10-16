<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpportunityRequest extends FormRequest
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
            'currency' => $this->filled('currency') ? (string) $this->input('currency') : 'IQD',
            'stage_id' => $this->filled('stage_id') ? $this->input('stage_id') : null,
            'assigned_to' => $this->filled('assigned_to') ? $this->input('assigned_to') : null,
            'expected_close_date' => $this->filled('expected_close_date') ? $this->input('expected_close_date') : null,
            'value' => $this->filled('value') ? $this->input('value') : null,
        ]);
    }

    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;

        return [
            'customer_id' => [
                'required', 'integer',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'currency' => ['required', 'string', 'max:10'],
            'stage_id' => [
                'nullable', 'integer',
                Rule::exists('opportunity_stages', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'expected_close_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['open', 'won', 'lost'])],
            'assigned_to' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId)->where('active', true)),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.exists' => __('crm.validation.customer_in_org'),
            'stage_id.exists' => __('crm.validation.stage_in_org'),
            'assigned_to.exists' => __('crm.validation.assigned_active'),
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_id' => __('crm.fields.customer'),
            'title' => __('crm.fields.title'),
            'description' => __('crm.fields.description'),
            'value' => __('crm.fields.value'),
            'currency' => __('crm.fields.currency'),
            'stage_id' => __('crm.fields.stage'),
            'expected_close_date' => __('crm.fields.expected_close_date'),
            'status' => __('crm.fields.status'),
            'assigned_to' => __('crm.fields.assigned_to'),
        ];
    }
}
