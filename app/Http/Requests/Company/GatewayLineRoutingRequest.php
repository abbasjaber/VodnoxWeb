<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the inbound routing of ONE gateway port.
 *
 * The administrator picks employee extensions of the *same* organization only;
 * the server re-checks ownership on save and never trusts a posted id. Ring
 * groups / IVR / queues are deliberately not offered here: the Asterisk agent
 * does not implement them for gateway lines yet, and a destination that is
 * stored in the database but cannot actually be dialled would be a lie.
 */
class GatewayLineRoutingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Free-text is stripped of CR/LF so a label can never be injected into the
     * generated dialplan, and times are normalised to H:i regardless of whether
     * the browser sent seconds.
     */
    protected function prepareForValidation(): void
    {
        $clean = function ($value) {
            if (! is_string($value)) {
                return $value;
            }

            return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
        };

        $merge = [];

        if (is_array($this->input('exceptions'))) {
            $merge['exceptions'] = array_map(function ($row) use ($clean) {
                if (! is_array($row)) {
                    return $row;
                }

                return array_merge($row, ['label' => $clean($row['label'] ?? null)]);
            }, $this->input('exceptions'));
        }

        if (is_array($this->input('business_hours'))) {
            $merge['business_hours'] = array_map(function ($row) {
                if (! is_array($row)) {
                    return $row;
                }

                foreach (['start', 'end'] as $key) {
                    if (isset($row[$key]) && is_string($row[$key])) {
                        $row[$key] = substr(trim($row[$key]), 0, 5);
                    }
                }

                return $row;
            }, $this->input('business_hours'));
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        $destination = [
            'nullable', 'integer',
            Rule::exists('extensions', 'id')->where(
                fn ($query) => $query->where('organization_id', $organizationId)->where('status', 'active')
            ),
        ];

        return [
            'default_destination_extension_id' => $destination,
            'outside_hours_destination_extension_id' => $destination,
            'weekend_destination_extension_id' => $destination,
            'fallback_destination_extension_id' => $destination,

            'temporary_forward_enabled' => ['nullable', 'boolean'],
            'temporary_forward_extension_id' => $destination,

            'business_hours' => ['nullable', 'array'],
            'business_hours.*.day' => ['required', 'integer', 'between:0,6'],
            'business_hours.*.start' => ['required', 'date_format:H:i'],
            'business_hours.*.end' => ['required', 'date_format:H:i'],

            'weekend_days' => ['nullable', 'array'],
            'weekend_days.*' => ['integer', 'between:0,6'],

            'exceptions' => ['nullable', 'array'],
            'exceptions.*.starts_on' => ['required', 'date_format:Y-m-d'],
            'exceptions.*.ends_on' => ['required', 'date_format:Y-m-d'],
            'exceptions.*.destination_extension_id' => $destination,
            'exceptions.*.label' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $invalid = __('company.destination_not_in_organization');

        return [
            'default_destination_extension_id.exists' => $invalid,
            'outside_hours_destination_extension_id.exists' => $invalid,
            'weekend_destination_extension_id.exists' => $invalid,
            'fallback_destination_extension_id.exists' => $invalid,
            'temporary_forward_extension_id.exists' => $invalid,
            'exceptions.*.destination_extension_id.exists' => $invalid,
        ];
    }
}
