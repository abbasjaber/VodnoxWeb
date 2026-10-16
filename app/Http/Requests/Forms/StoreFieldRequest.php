<?php

namespace App\Http\Requests\Forms;

use App\Services\Forms\FormDefinitionValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(FormDefinitionValidator::TYPES)],
            'label_ar' => ['required', 'string', 'max:255'],
            'label_en' => ['nullable', 'string', 'max:255'],
            'help_text_ar' => ['nullable', 'string', 'max:500'],
            'help_text_en' => ['nullable', 'string', 'max:500'],
            'placeholder_ar' => ['nullable', 'string', 'max:255'],
            'placeholder_en' => ['nullable', 'string', 'max:255'],
            'is_required' => ['nullable', 'boolean'],
            'min_length' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'max_length' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'min_value' => ['nullable', 'integer'],
            'max_value' => ['nullable', 'integer'],
            'max_rating' => ['nullable', 'integer', 'min:2', 'max:10'],
            'max_selections' => ['nullable', 'integer', 'min:1', 'max:30'],

            'options' => ['nullable', 'array', 'max:30'],
            'options.*' => ['nullable'],

            'conditions' => ['nullable', 'array', 'max:5'],
            'conditions.*.depends_on_field_id' => ['required', 'integer', 'min:1'],
            'conditions.*.operator' => ['required', Rule::in(FormDefinitionValidator::OPERATORS)],
            'conditions.*.value' => ['required', 'string', 'max:255'],
            'conditions.*.action' => ['required', Rule::in(FormDefinitionValidator::ACTIONS)],
        ];
    }
}
