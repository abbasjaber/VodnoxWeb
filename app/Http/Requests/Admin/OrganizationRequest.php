<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organization = $this->route('organization');
        $isCreate = $this->isMethod('post');

        $rules = [
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_\-]+$/', Rule::unique('organizations', 'code')->ignore($organization?->id)],
            'name_ar' => ['required', 'string', 'max:190'],
            'name_en' => ['required', 'string', 'max:190'],
            'contact_name' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::in(['pending', 'active', 'trial', 'suspended', 'expired'])],
            'timezone' => ['nullable', 'string', 'max:64'],
            'language' => ['required', Rule::in(['ar', 'en'])],
            'internal_notes' => ['nullable', 'string'],
        ];

        if ($isCreate) {
            $rules['admin_name'] = ['required', 'string', 'max:190'];
            $rules['admin_username'] = ['required', 'string', 'max:190', 'unique:users,username'];
            $rules['admin_email'] = ['nullable', 'email', 'max:190'];
            $rules['admin_password'] = ['required', 'string', 'min:8'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'code' => __('admin.org_code'),
            'name_ar' => __('admin.name_ar'),
            'name_en' => __('admin.name_en'),
            'admin_name' => __('admin.org_admin_name'),
            'admin_username' => __('admin.org_admin_username'),
            'admin_password' => __('admin.org_admin_password'),
        ];
    }
}
