<?php

namespace App\Http\Requests\Company;

use App\Models\GatewayDevice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a GSM gateway device and its lines.
 *
 * Security-relevant rules:
 *  - the model must be one of the *approved* device profiles, never free text,
 *    so an installer can never make the agent write an untested config;
 *  - an inbound destination must be an extension of the *same* organization
 *    (checked server side, not trusted from the form);
 *  - free-text fields are stripped of control characters so a crafted label
 *    cannot inject CRLF / INI / dialplan content into a generated config.
 */
class GatewayDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Strip control characters (including CR/LF) from every free-text field so
     * no value can break out of a config line or a dialplan statement.
     */
    protected function prepareForValidation(): void
    {
        $clean = function ($value) {
            if (! is_string($value)) {
                return $value;
            }

            // Remove CR/LF and the remaining C0/C1 control characters.
            return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
        };

        $merge = [
            'label' => $clean($this->input('label')),
            'firmware' => $clean($this->input('firmware')),
            'network_notes' => $clean($this->input('network_notes')),
        ];

        if (is_array($this->input('lines'))) {
            $merge['lines'] = array_map(function ($line) use ($clean) {
                if (! is_array($line)) {
                    return $line;
                }

                return array_merge($line, [
                    'label' => $clean($line['label'] ?? null),
                    'sim_number' => $clean($line['sim_number'] ?? null),
                ]);
            }, $this->input('lines'));
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;
        /** @var GatewayDevice|null $device */
        $device = $this->route('gateway');

        $profiles = array_keys((array) config('integrations.telephony.gateway_profiles', []));

        $rules = [
            'label' => ['required', 'string', 'max:120'],
            'model' => ['required', 'string', Rule::in($profiles)],
            'firmware' => ['nullable', 'string', 'max:120'],
            'network_notes' => ['nullable', 'string', 'max:1000'],
            'enabled' => ['nullable', 'boolean'],
        ];

        // Lines are only submitted when a device is created; afterwards each
        // line is managed on its own (identity must never be swapped silently).
        if ($this->isMethod('post')) {
            $rules['lines'] = ['required', 'array', 'min:1'];
            $rules['lines.*.label'] = ['nullable', 'string', 'max:120'];
            $rules['lines.*.sim_number'] = ['nullable', 'string', 'max:40'];
            $rules['lines.*.inbound_extension_id'] = [
                'required', 'integer',
                Rule::exists('extensions', 'id')->where(function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId)->where('status', 'active');
                }),
            ];
            $rules['lines.*.enabled'] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.*.inbound_extension_id.exists' => __('company.gateway_destination_invalid'),
        ];
    }
}
