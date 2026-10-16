<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FavoriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'contact_id' => $this->filled('contact_id') ? $this->input('contact_id') : null,
            'name' => $this->filled('name') ? trim((string) $this->input('name')) : null,
            'phone_number' => $this->filled('phone_number') ? trim((string) $this->input('phone_number')) : null,
        ]);
    }

    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;

        return [
            'contact_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query->where('organization_id', $organizationId)->where('active', true)
                ),
            ],
            'name' => ['nullable', 'required_without:contact_id', 'string', 'max:255'],
            'phone_number' => ['nullable', 'required_without:contact_id', 'string', 'max:32'],
        ];
    }

    public function messages(): array
    {
        return [
            'contact_id.exists' => __('portal.agent.contact_in_org'),
            'name.required_without' => __('portal.agent.need_name_or_contact'),
            'phone_number.required_without' => __('portal.agent.need_name_or_contact'),
        ];
    }

    public function attributes(): array
    {
        return [
            'contact_id' => __('portal.agent.select_contact'),
            'name' => __('portal.agent.name'),
            'phone_number' => __('portal.agent.phone'),
        ];
    }
}
