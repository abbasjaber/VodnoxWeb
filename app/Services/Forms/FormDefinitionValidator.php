<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormField;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalizes form field definitions and their visibility
 * conditions.
 *
 * Critical rule: a condition must never (a) reference its own field,
 * (b) reference a field that comes AFTER the current field (forward
 * reference), or (c) create a dependency cycle.
 */
class FormDefinitionValidator
{
    public const TYPES = [
        'short_text', 'long_text', 'email', 'phone', 'number',
        'select', 'radio', 'checkbox', 'date', 'rating', 'section',
    ];

    public const OPERATORS = ['equals', 'not_equals', 'contains', 'greater_than', 'less_than'];

    public const ACTIONS = ['show', 'hide'];

    public const CHOICE_TYPES = ['select', 'radio', 'checkbox'];

    public const TEXT_TYPES = ['short_text', 'long_text', 'email', 'phone'];

    /**
     * Validate + normalize a single field definition.
     *
     * @return array{field: array<string,mixed>, options: array<int,array<string,mixed>>, conditions: array<int,array<string,mixed>>}
     */
    public function normalizeFieldDefinition(array $data, int $order): array
    {
        $type = (string) ($data['type'] ?? '');

        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => __('forms.errors.invalid_type')]);
        }

        $labelAr = trim((string) ($data['label_ar'] ?? ''));
        if ($labelAr === '') {
            throw ValidationException::withMessages(['label_ar' => __('forms.errors.label_required')]);
        }

        $field = [
            'type' => $type,
            'label_ar' => $labelAr,
            'label_en' => $this->nullableString($data['label_en'] ?? null),
            'help_text_ar' => $this->nullableString($data['help_text_ar'] ?? null),
            'help_text_en' => $this->nullableString($data['help_text_en'] ?? null),
            'placeholder_ar' => $this->nullableString($data['placeholder_ar'] ?? null),
            'placeholder_en' => $this->nullableString($data['placeholder_en'] ?? null),
            'is_required' => (bool) ($data['is_required'] ?? false),
            'order' => $order,
            'min_length' => null,
            'max_length' => null,
            'min_value' => null,
            'max_value' => null,
            'max_rating' => null,
            'max_selections' => null,
        ];

        // A section is a structural heading, never a data field.
        if ($type === 'section') {
            $field['is_required'] = false;
        }

        if (in_array($type, self::TEXT_TYPES, true)) {
            $field['placeholder_ar'] = $this->nullableString($data['placeholder_ar'] ?? null);
            $field['placeholder_en'] = $this->nullableString($data['placeholder_en'] ?? null);
            $field['min_length'] = $this->nullableInt($data['min_length'] ?? null);
            $field['max_length'] = $this->nullableInt($data['max_length'] ?? null);
        }

        if ($type === 'number') {
            $field['min_value'] = $this->nullableInt($data['min_value'] ?? null);
            $field['max_value'] = $this->nullableInt($data['max_value'] ?? null);
        }

        if ($type === 'rating') {
            $field['max_rating'] = $this->nullableInt($data['max_rating'] ?? null) ?? 5;
        }

        if ($type === 'checkbox') {
            $field['max_selections'] = $this->nullableInt($data['max_selections'] ?? null);
        }

        $options = [];
        if (in_array($type, self::CHOICE_TYPES, true)) {
            $options = $this->normalizeOptions($data['options'] ?? []);
            if ($options === []) {
                throw ValidationException::withMessages(['options' => __('forms.errors.options_required')]);
            }
        }

        $conditions = $this->normalizeConditions($data['conditions'] ?? []);

        return ['field' => $field, 'options' => $options, 'conditions' => $conditions];
    }

    /**
     * Semantic validation of conditions against the (already persisted) field.
     * Must be called AFTER the field row exists so a real id is available for
     * cycle detection.
     *
     * @param array<int,array<string,mixed>> $conditions
     */
    public function assertConditionsValid(Form $form, FormField $field, array $conditions): void
    {
        if ($conditions === []) {
            return;
        }

        $allFields = $form->fields()->with('conditions')->get();
        $byId = $allFields->keyBy('id');

        // Build the current dependency graph: field id => [depends_on ids].
        $graph = [];
        foreach ($allFields as $existing) {
            foreach ($existing->conditions as $condition) {
                $graph[$existing->id][] = (int) $condition->depends_on_field_id;
            }
        }
        $graph[$field->id] = $graph[$field->id] ?? [];

        foreach ($conditions as $condition) {
            $dependsOn = (int) $condition['depends_on_field_id'];

            // (a) self reference
            if ($dependsOn === (int) $field->id) {
                throw ValidationException::withMessages(['conditions' => __('forms.errors.condition_self')]);
            }

            // Must reference a field of the same form.
            if (! $byId->has($dependsOn)) {
                throw ValidationException::withMessages(['conditions' => __('forms.errors.condition_forward')]);
            }

            // (b) forward reference: the dependency must come strictly before.
            if ((int) $byId[$dependsOn]->order >= (int) $field->order) {
                throw ValidationException::withMessages(['conditions' => __('forms.errors.condition_forward')]);
            }

            // (c) cycle detection.
            $graph[$field->id][] = $dependsOn;
            if ($this->detectCycle($graph, (int) $field->id)) {
                throw ValidationException::withMessages(['conditions' => __('forms.errors.condition_cycle')]);
            }
        }
    }

    /**
     * Depth-first cycle detection starting from $start.
     *
     * @param array<int,int|array<int,int>> $graph
     */
    private function detectCycle(array $graph, int $start): bool
    {
        $stack = $graph[$start] ?? [];
        $visited = [];

        while ($stack !== []) {
            $node = array_pop($stack);

            if ($node === $start) {
                return true;
            }
            if (isset($visited[$node])) {
                continue;
            }
            $visited[$node] = true;

            foreach ($graph[$node] ?? [] as $next) {
                $stack[] = $next;
            }
        }

        return false;
    }

    /**
     * @param array<int,mixed> $raw
     * @return array<int,array<string,mixed>>
     */
    private function normalizeOptions(array $raw): array
    {
        $options = [];
        $seen = [];
        $index = 0;

        foreach ($raw as $option) {
            if (is_string($option)) {
                $labelAr = trim($option);
                $labelEn = $labelAr;
                $value = $labelAr;
            } elseif (is_array($option)) {
                $labelAr = trim((string) ($option['label_ar'] ?? ''));
                $labelEn = trim((string) ($option['label_en'] ?? '')) ?: $labelAr;
                $value = trim((string) ($option['value'] ?? '')) ?: $labelEn;
            } else {
                continue;
            }

            if ($labelAr === '' || $value === '') {
                continue;
            }

            // Keep option values unique so answer membership checks are stable.
            $key = mb_strtolower($value);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $options[] = [
                'label_ar' => $labelAr,
                'label_en' => $labelEn !== '' ? $labelEn : null,
                'value' => $value,
                'order' => ++$index,
            ];
        }

        return $options;
    }

    /**
     * @param array<int,mixed> $raw
     * @return array<int,array<string,mixed>>
     */
    private function normalizeConditions(array $raw): array
    {
        $conditions = [];

        foreach ($raw as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $dependsOn = (int) ($condition['depends_on_field_id'] ?? 0);
            $operator = (string) ($condition['operator'] ?? '');
            $action = (string) ($condition['action'] ?? 'show');

            if ($dependsOn <= 0 || ! in_array($operator, self::OPERATORS, true) || ! in_array($action, self::ACTIONS, true)) {
                continue;
            }

            $conditions[] = [
                'depends_on_field_id' => $dependsOn,
                'operator' => $operator,
                'value' => (string) ($condition['value'] ?? ''),
                'action' => $action,
            ];
        }

        return $conditions;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
