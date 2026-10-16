<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_map(
            fn ($value) => is_string($value) ? trim($value) : $value,
            [
                'name' => $this->input('name'),
                'email' => $this->input('email'),
                'phone' => $this->input('phone'),
                'company' => $this->input('company'),
                'message' => $this->input('message'),
            ]
        ));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'company' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => __('portal.public.field_name'),
            'email' => __('portal.public.field_email'),
            'phone' => __('portal.public.field_phone'),
            'company' => __('portal.public.field_company'),
            'message' => __('portal.public.field_message'),
        ];
    }
}
