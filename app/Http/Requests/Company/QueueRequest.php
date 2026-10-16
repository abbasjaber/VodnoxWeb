<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QueueRequest extends FormRequest
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
        $organizationId = $this->user()->organization_id;

        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'strategy' => ['required', Rule::in(['ring_all', 'least_recent', 'fewest_calls', 'random'])],
            'timeout' => ['required', 'integer', 'min:5', 'max:300'],
            'is_active' => ['sometimes', 'boolean'],
            'members' => ['required', 'array', 'min:1'],
            'members.*' => [
                'integer', 'distinct',
                Rule::exists('users', 'id')
                    ->where(fn ($q) => $q->where('organization_id', $organizationId)->where('active', true)),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'members' => array_values(array_filter((array) $this->input('members', []))),
        ]);
    }
}
