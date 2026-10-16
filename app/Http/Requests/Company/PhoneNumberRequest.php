<?php

namespace App\Http\Requests\Company;

use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PhoneNumberRequest extends FormRequest
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
        /** @var PhoneNumber|null $number */
        $number = $this->route('number');

        return [
            'number' => [
                'required', 'string', 'max:50',
                Rule::unique('phone_numbers', 'number')
                    ->where(fn ($q) => $q->where('organization_id', $organizationId))
                    ->ignore($number?->getKey()),
            ],
            'label' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'routing' => ['required', 'string', 'regex:/^(extension|ivr|queue|voicemail)(:\d+)?$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $organizationId = (int) $this->user()->organization_id;
            [$type, $id] = array_pad(explode(':', (string) $this->input('routing'), 2), 2, null);

            if (in_array($type, ['voicemail'], true)) {
                return;
            }

            if (! $id) {
                $v->errors()->add('routing', __('company.routing_hint'));

                return;
            }

            $exists = match ($type) {
                'extension' => Extension::query()->whereKey((int) $id)->where('organization_id', $organizationId)->exists(),
                'ivr' => IvrMenu::query()->whereKey((int) $id)->where('organization_id', $organizationId)->exists(),
                'queue' => CallQueue::query()->whereKey((int) $id)->where('organization_id', $organizationId)->exists(),
                default => false,
            };

            if (! $exists) {
                $v->errors()->add('routing', __('company.routing_hint'));
            }
        });
    }
}
