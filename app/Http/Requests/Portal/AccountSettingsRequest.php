<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => $this->filled('phone') ? trim((string) $this->input('phone')) : null,
            'audio_device' => $this->filled('audio_device') ? trim((string) $this->input('audio_device')) : null,
            'auto_answer' => $this->boolean('auto_answer'),
            'do_not_disturb' => $this->boolean('do_not_disturb'),
        ]);
    }

    public function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:32'],
            'audio_device' => ['nullable', Rule::in(['default', 'headset'])],
            'auto_answer' => ['boolean'],
            'do_not_disturb' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'phone' => __('portal.agent.phone'),
            'audio_device' => __('portal.agent.preferred_mic'),
        ];
    }
}
