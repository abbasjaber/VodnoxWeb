<?php

namespace App\Http\Requests\Company;

use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\IvrMenu;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IvrMenuRequest extends FormRequest
{
    private const TYPES = ['extension', 'voicemail', 'hangup', 'menu', 'queue', 'repeat'];

    private const MENU_TYPES = ['extension', 'voicemail', 'hangup', 'menu', 'queue'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'greeting_ar' => ['nullable', 'string', 'max:1000'],
            'greeting_en' => ['nullable', 'string', 'max:1000'],
            'timeout' => ['required', 'integer', 'between:3,30'],
            'max_attempts' => ['required', 'integer', 'between:0,3'],
            'is_active' => ['sometimes', 'boolean'],
            'invalid_destination_type' => ['required', Rule::in(self::TYPES)],
            'invalid_destination_id' => ['nullable', 'integer'],
            'no_input_destination_type' => ['required', Rule::in(self::TYPES)],
            'no_input_destination_id' => ['nullable', 'integer'],
            'options' => ['array'],
            'options.*.digit' => ['required', 'string', 'size:1', 'regex:/^[0-9]$/', 'distinct'],
            'options.*.destination_type' => ['required', Rule::in(self::MENU_TYPES)],
            'options.*.destination_id' => ['nullable', 'integer'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $options = array_values(array_filter(
            (array) $this->input('options', []),
            fn ($option) => isset($option['digit']) && $option['digit'] !== '',
        ));

        // The UI submits a single "type:id" destination value; split it into the
        // destination_type / destination_id pair the persistence layer expects.
        $options = array_map(function (array $option): array {
            if (! empty($option['destination'])) {
                [$type, $id] = array_pad(explode(':', (string) $option['destination'], 2), 2, null);
                $option['destination_type'] = $type;
                $option['destination_id'] = $id !== null && $id !== '' ? (int) $id : null;
            }

            return $option;
        }, $options);

        foreach (['invalid', 'no_input'] as $prefix) {
            $value = (string) $this->input($prefix.'_destination', '');
            if ($value !== '') {
                [$type, $id] = array_pad(explode(':', $value, 2), 2, null);
                $this->merge([
                    $prefix.'_destination_type' => $type,
                    $prefix.'_destination_id' => $id !== null && $id !== '' ? (int) $id : null,
                ]);
            }
        }

        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'options' => $options,
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $organizationId = (int) $this->user()->organization_id;

            $this->assertDestination(
                $v,
                $this->input('invalid_destination_type'),
                $this->input('invalid_destination_id'),
                'invalid_destination_id',
                $organizationId,
            );

            $this->assertDestination(
                $v,
                $this->input('no_input_destination_type'),
                $this->input('no_input_destination_id'),
                'no_input_destination_id',
                $organizationId,
            );

            foreach ((array) $this->input('options', []) as $index => $option) {
                $this->assertDestination(
                    $v,
                    $option['destination_type'] ?? null,
                    $option['destination_id'] ?? null,
                    "options.$index.destination_id",
                    $organizationId,
                );
            }
        });
    }

    private function assertDestination(Validator $v, mixed $type, mixed $id, string $errorKey, int $organizationId): void
    {
        if ($type === null || in_array($type, ['voicemail', 'hangup', 'repeat'], true)) {
            return;
        }

        if (! $id) {
            $v->errors()->add($errorKey, __('validation.required', ['attribute' => __('company.destination')]));

            return;
        }

        $exists = match ($type) {
            'extension' => Extension::query()->whereKey((int) $id)->where('organization_id', $organizationId)->exists(),
            'queue' => CallQueue::query()->whereKey((int) $id)->where('organization_id', $organizationId)->exists(),
            'menu' => IvrMenu::query()->whereKey((int) $id)->where('organization_id', $organizationId)->exists(),
            default => true,
        };

        if (! $exists) {
            $v->errors()->add($errorKey, __('company.routing_hint'));
        }
    }
}
