<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->route('user');
        $isCreate = $this->isMethod('post');

        return [
            'name' => ['required', 'string', 'max:190'],
            'username' => ['required', 'string', 'max:190', Rule::unique('users', 'username')->ignore($user?->id)],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
            'position' => ['nullable', 'string', 'max:190'],
            'password' => [$isCreate ? 'required' : 'nullable', 'string', 'min:8'],
        ];
    }

    public function messages(): array
    {
        return ['password.min' => __('admin.val_password_min')];
    }
}
