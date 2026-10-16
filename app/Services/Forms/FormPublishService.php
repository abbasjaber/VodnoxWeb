<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\FormField;
use App\Models\FormOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Publishes a form: creates an immutable, versioned schema snapshot and marks
 * the form as published with a stable public token.
 *
 * Old versions are never deleted: every submission keeps its form_version_id
 * so editing fields after publish can never break previous responses.
 */
class FormPublishService
{
    public function publish(Form $form): FormVersion
    {
        return DB::transaction(function () use ($form) {
            $nextVersion = (int) $form->versions()->max('version') + 1;

            // Only one version is the "current published" one at a time; older
            // versions stay intact for their existing submissions.
            $form->versions()->where('is_published', true)->update(['is_published' => false]);

            $version = $form->versions()->create([
                'version' => $nextVersion,
                'schema' => $this->snapshot($form, $nextVersion),
                'is_published' => true,
                'published_at' => now(),
            ]);

            if (empty($form->public_token)) {
                $form->public_token = Str::random(32);
            }

            $form->status = 'published';
            $form->is_active = true;
            $form->save();

            return $version;
        });
    }

    /**
     * Build a complete snapshot of the form definition (fields, options and
     * conditions) so that published behaviour is frozen.
     *
     * @return array<string,mixed>
     */
    public function snapshot(Form $form, int $version): array
    {
        $fields = $form->fields()->with(['options', 'conditions'])->get();

        return [
            'version' => $version,
            'fields' => $fields->map(fn (FormField $field) => [
                'id' => (int) $field->id,
                'type' => $field->type,
                'label_ar' => $field->label_ar,
                'label_en' => $field->label_en,
                'help_text_ar' => $field->help_text_ar,
                'help_text_en' => $field->help_text_en,
                'placeholder_ar' => $field->placeholder_ar,
                'placeholder_en' => $field->placeholder_en,
                'is_required' => (bool) $field->is_required,
                'order' => (int) $field->order,
                'min_length' => $field->min_length,
                'max_length' => $field->max_length,
                'min_value' => $field->min_value,
                'max_value' => $field->max_value,
                'max_rating' => $field->max_rating,
                'max_selections' => $field->max_selections,
                'options' => $field->options->map(fn (FormOption $option) => [
                    'label_ar' => $option->label_ar,
                    'label_en' => $option->label_en,
                    'value' => $option->value,
                    'order' => (int) $option->order,
                ])->values()->all(),
                'conditions' => $field->conditions->map(fn ($condition) => [
                    'depends_on_field_id' => (int) $condition->depends_on_field_id,
                    'operator' => $condition->operator,
                    'value' => $condition->value,
                    'action' => $condition->action,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
