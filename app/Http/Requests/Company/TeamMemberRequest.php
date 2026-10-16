<?php

namespace App\Http\Requests\Company;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User|null $member */
        $member = $this->route('member');
        $id = $member?->getKey();

        $assignableRoles = Role::query()
            ->whereIn('name', ['organization_admin', 'supervisor', 'agent', 'viewer'])
            ->pluck('id')
            ->all();

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('users', 'username')->ignore($id),
            ],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($id),
            ],
            'role_id' => ['required', 'integer', Rule::in($assignableRoles)],
            'position' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'extension' => ['nullable', 'string', 'max:20'],
            'active' => ['sometimes', 'boolean'],
            'password' => [$id ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'active' => $this->boolean('active'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role_id.in' => __('company.cannot_assign_super_admin'),
        ];
    }
}
