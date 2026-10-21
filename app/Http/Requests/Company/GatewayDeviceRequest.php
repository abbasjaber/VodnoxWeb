<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a GSM gateway device and (optionally) its ports.
 *
 * Adding a device is deliberately short and never asks for an inbound
 * destination, an employee or a schedule: those belong to the numbers & routing
 * workspace. The model profile alone fixes how many ports the device has.
 *
 * Security-relevant rules:
 *  - the model must be one of the *approved* device profiles, never free text,
 *    so an installer can never make the agent write an untested config;
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
        $profiles = array_keys((array) config('integrations.telephony.gateway_profiles', []));

        $rules = [
            'label' => ['required', 'string', 'max:120'],
            'model' => ['required', 'string', Rule::in($profiles)],
            'firmware' => ['nullable', 'string', 'max:120'],
            'network_notes' => ['nullable', 'string', 'max:1000'],
            'enabled' => ['nullable', 'boolean'],
        ];

        // Ports are created server-side from the model profile. On create an
        // administrator may optionally mark which ports carry a SIM; an inbound
        // destination is *never* collected here.
        if ($this->isMethod('post')) {
            $rules['lines'] = ['nullable', 'array'];
            $rules['lines.*.label'] = ['nullable', 'string', 'max:120'];
            $rules['lines.*.sim_number'] = ['nullable', 'string', 'max:40'];
            $rules['lines.*.enabled'] = ['nullable', 'boolean'];
        }

        return $rules;
    }
}
