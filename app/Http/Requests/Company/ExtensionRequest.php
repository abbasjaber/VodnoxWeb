<?php

namespace App\Http\Requests\Company;

use App\Models\Extension;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExtensionRequest extends FormRequest
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
        /** @var Extension|null $extension */
        $extension = $this->route('extension');

        return [
            'extension' => [
                'required', 'string', 'max:20', 'regex:/^[0-9]{2,10}$/',
                Rule::unique('extensions', 'extension')
                    ->where(fn ($q) => $q->where('organization_id', $organizationId))
                    ->ignore($extension?->getKey()),
            ],
            'label' => ['nullable', 'string', 'max:255'],
            'user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('organization_id', $organizationId)),
            ],
            'status' => ['nullable', Rule::in(['inactive', 'active', 'provisioning'])],
        ];
    }
}
