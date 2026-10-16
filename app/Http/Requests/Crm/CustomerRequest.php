<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name_ar' => $this->filled('name_ar') ? trim((string) $this->input('name_ar')) : $this->input('name_ar'),
            'name_en' => $this->filled('name_en') ? trim((string) $this->input('name_en')) : null,
            'company_name' => $this->filled('company_name') ? trim((string) $this->input('company_name')) : null,
            'email' => $this->filled('email') ? trim(mb_strtolower((string) $this->input('email'))) : null,
            'phone' => $this->filled('phone') ? trim((string) $this->input('phone')) : null,
            'address' => $this->filled('address') ? trim((string) $this->input('address')) : null,
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
            'assigned_to' => $this->filled('assigned_to') ? $this->input('assigned_to') : null,
        ]);
    }

    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;
        $customer = $this->route('customer');
        $ignoreId = $customer?->id;

        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'phone' => [
                'nullable', 'string', 'max:50',
                Rule::unique('customers', 'phone')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId))
                    ->whereNull('deleted_at')
                    ->ignore($ignoreId),
            ],
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('customers', 'email')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId))
                    ->whereNull('deleted_at')
                    ->ignore($ignoreId),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'assigned_to' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId)->where('active', true)),
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.unique' => __('crm.validation.phone_unique'),
            'email.unique' => __('crm.validation.email_unique'),
            'assigned_to.exists' => __('crm.validation.assigned_active'),
        ];
    }

    public function attributes(): array
    {
        return [
            'name_ar' => __('crm.fields.name_ar'),
            'name_en' => __('crm.fields.name_en'),
            'company_name' => __('crm.fields.company_name'),
            'email' => __('crm.fields.email'),
            'phone' => __('crm.fields.phone'),
            'address' => __('crm.fields.address'),
            'status' => __('crm.fields.status'),
            'assigned_to' => __('crm.fields.assigned_to'),
            'notes' => __('crm.fields.notes'),
        ];
    }
}
