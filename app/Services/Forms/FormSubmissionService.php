<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\OrganizationFeatureSubscription;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Validates and stores public submissions.
 *
 * Answers are validated SERVER-SIDE against the PUBLISHED schema snapshot
 * (never the live draft fields), and the quota check + insert run inside a
 * single locked transaction so two simultaneous submissions can never exceed
 * max_submissions.
 */
class FormSubmissionService
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * The current published version of a form, or null.
     */
    public function publishedVersion(Form $form): ?FormVersion
    {
        return $form->versions()->where('is_published', true)->orderByDesc('version')->first();
    }

    /**
     * Persist a validated submission or throw a ValidationException.
     *
     * @param array<string|int,mixed> $answers
     */
    public function submit(Form $form, array $answers, ?string $ipAddress, ?string $userAgent): FormSubmission
    {
        $version = $this->publishedVersion($form);
        if (! $version) {
            throw ValidationException::withMessages(['form' => __('forms.errors.not_published')]);
        }

        $cleanAnswers = $this->validateAgainstSchema($version->schema ?? [], $answers);

        return DB::transaction(function () use ($form, $version, $cleanAnswers, $ipAddress, $userAgent) {
            // Row lock: serializes the last-slot race for a single form.
            $locked = Form::whereKey($form->getKey())->lockForUpdate()->firstOrFail();

            $this->assertAccepting($locked);

            // Also serialize the organization-wide submission quota by locking
            // the feature subscription row (guards other forms in the org).
            $organization = $locked->organization;
            $subscription = $organization
                ? $this->subscriptions->featureSubscription($organization, 'forms')
                : null;

            if ($subscription) {
                OrganizationFeatureSubscription::whereKey($subscription->getKey())->lockForUpdate()->first();
            }

            // Re-count under the lock before deciding.
            if ($locked->max_submissions && $locked->submissions()->count() >= $locked->max_submissions) {
                throw ValidationException::withMessages(['form' => __('forms.errors.quota_reached')]);
            }

            if ($organization && $subscription && ! $this->subscriptions->withinLimit($organization, 'forms', 'submissions', 1)) {
                throw ValidationException::withMessages(['form' => __('forms.errors.quota_reached')]);
            }

            return FormSubmission::create([
                'form_id' => $locked->getKey(),
                'form_version_id' => $version->getKey(),
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'answers' => $cleanAnswers,
            ]);
        });
    }

    /**
     * Validate answers against a published schema snapshot and return only the
     * answers that belong to visible, non-section fields.
     *
     * @param array<string,mixed> $schema
     * @param array<string|int,mixed> $answers
     * @return array<int,mixed>
     */
    public function validateAgainstSchema(array $schema, array $answers): array
    {
        $fields = $schema['fields'] ?? [];
        $incoming = $this->normalizeIncomingAnswers($answers);

        $visible = $this->computeVisibility($fields, $incoming);
        $errors = [];
        $clean = [];

        foreach ($fields as $field) {
            $type = $field['type'] ?? '';
            if ($type === 'section') {
                continue;
            }

            $id = (int) $field['id'];

            // Hidden fields (unsatisfied condition) are excluded from validation.
            if (! ($visible[$id] ?? true)) {
                continue;
            }

            $required = (bool) ($field['is_required'] ?? false);
            $raw = $incoming[$id] ?? null;
            $value = $this->validateField($field, $raw, $required, $errors);

            if ($value !== null) {
                $clean[$id] = $value;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @param array<int,mixed> $answers
     * @return array<int,bool>
     */
    private function computeVisibility(array $fields, array $answers): array
    {
        $visible = [];

        foreach ($fields as $field) {
            $id = (int) $field['id'];
            $show = true;

            foreach ($field['conditions'] ?? [] as $condition) {
                $dependsOn = (int) ($condition['depends_on_field_id'] ?? 0);
                $dependsVisible = $visible[$dependsOn] ?? false;
                $dependsValue = $answers[$dependsOn] ?? null;

                $satisfied = $dependsVisible
                    && $this->evaluateOperator((string) ($condition['operator'] ?? ''), $dependsValue, (string) ($condition['value'] ?? ''));

                $action = $condition['action'] ?? 'show';
                if ($action === 'show' && ! $satisfied) {
                    $show = false;
                }
                if ($action === 'hide' && $satisfied) {
                    $show = false;
                }
            }

            $visible[$id] = $show;
        }

        return $visible;
    }

    private function evaluateOperator(string $operator, mixed $answer, string $expected): bool
    {
        if (is_array($answer)) {
            return match ($operator) {
                'equals', 'contains' => in_array($expected, array_map('strval', $answer), true),
                'not_equals' => ! in_array($expected, array_map('strval', $answer), true),
                default => false,
            };
        }

        $actual = (string) ($answer ?? '');

        return match ($operator) {
            'equals' => $actual === $expected,
            'not_equals' => $actual !== $expected,
            'contains' => $expected !== '' && str_contains($actual, $expected),
            'greater_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'less_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            default => false,
        };
    }

    /**
     * @param array<string,mixed> $field
     * @param array<string,string> $errors
     */
    private function validateField(array $field, mixed $raw, bool $required, array &$errors): mixed
    {
        $id = (int) $field['id'];
        $type = $field['type'] ?? '';
        $label = $this->fieldLabel($field);
        $key = (string) $id;

        $isEmpty = $raw === null
            || (is_string($raw) && trim($raw) === '')
            || (is_array($raw) && $raw === []);

        if ($isEmpty) {
            if ($required) {
                $errors[$key] = __('forms.errors.field_required', ['field' => $label]);
            }

            return null;
        }

        switch ($type) {
            case 'short_text':
            case 'long_text':
                $value = trim((string) $raw);
                $length = mb_strlen($value);

                if (isset($field['min_length']) && $field['min_length'] !== null && $length < (int) $field['min_length']) {
                    $errors[$key] = __('forms.errors.too_short', ['field' => $label]);

                    return null;
                }
                if (isset($field['max_length']) && $field['max_length'] !== null && $length > (int) $field['max_length']) {
                    $errors[$key] = __('forms.errors.too_long', ['field' => $label]);

                    return null;
                }

                return $value;

            case 'email':
                $value = trim((string) $raw);
                if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$key] = __('forms.errors.invalid_email');

                    return null;
                }

                return $value;

            case 'phone':
                $value = trim((string) $raw);
                if (! preg_match('/^\+?[0-9\s\-()]{5,32}$/', $value)) {
                    $errors[$key] = __('forms.errors.invalid_phone');

                    return null;
                }

                return $value;

            case 'number':
                if (! is_numeric($raw)) {
                    $errors[$key] = __('forms.errors.invalid_number');

                    return null;
                }
                $number = (float) $raw;
                if (isset($field['min_value']) && $field['min_value'] !== null && $number < (float) $field['min_value']) {
                    $errors[$key] = __('forms.errors.number_min', ['field' => $label]);

                    return null;
                }
                if (isset($field['max_value']) && $field['max_value'] !== null && $number > (float) $field['max_value']) {
                    $errors[$key] = __('forms.errors.number_max', ['field' => $label]);

                    return null;
                }

                return $raw;

            case 'date':
                $value = trim((string) $raw);
                $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
                if (! $date || $date->format('Y-m-d') !== $value) {
                    $errors[$key] = __('forms.errors.invalid_date', ['field' => $label]);

                    return null;
                }

                return $value;

            case 'select':
            case 'radio':
                $value = trim((string) $raw);
                if (! $this->optionExists($field, $value)) {
                    $errors[$key] = __('forms.errors.invalid_option', ['field' => $label]);

                    return null;
                }

                return $value;

            case 'checkbox':
                $values = is_array($raw) ? array_values(array_map('strval', $raw)) : [(string) $raw];
                $values = array_values(array_filter($values, fn ($item) => $item !== ''));

                if ($values === []) {
                    if ($required) {
                        $errors[$key] = __('forms.errors.field_required', ['field' => $label]);
                    }

                    return null;
                }

                foreach ($values as $value) {
                    if (! $this->optionExists($field, $value)) {
                        $errors[$key] = __('forms.errors.invalid_option', ['field' => $label]);

                        return null;
                    }
                }

                if (! empty($field['max_selections']) && count($values) > (int) $field['max_selections']) {
                    $errors[$key] = __('forms.errors.max_selections', ['field' => $label]);

                    return null;
                }

                return $values;

            case 'rating':
                if (! is_numeric($raw) || (int) $raw != $raw) {
                    $errors[$key] = __('forms.errors.invalid_rating', ['field' => $label]);

                    return null;
                }
                $rating = (int) $raw;
                $maxRating = (int) ($field['max_rating'] ?? 5) ?: 5;
                if ($rating < 1 || $rating > $maxRating) {
                    $errors[$key] = __('forms.errors.invalid_rating', ['field' => $label]);

                    return null;
                }

                return $rating;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $field
     */
    private function optionExists(array $field, string $value): bool
    {
        foreach ($field['options'] ?? [] as $option) {
            if ((string) ($option['value'] ?? '') === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string|int,mixed> $answers
     * @return array<int,mixed>
     */
    private function normalizeIncomingAnswers(array $answers): array
    {
        $normalized = [];

        foreach ($answers as $key => $value) {
            if (is_numeric($key)) {
                $normalized[(int) $key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $field
     */
    private function fieldLabel(array $field): string
    {
        $ar = (string) ($field['label_ar'] ?? '');
        $en = (string) ($field['label_en'] ?? '');

        return app()->getLocale() === 'ar' ? ($ar ?: $en) : ($en ?: $ar);
    }

    private function assertAccepting(Form $form): void
    {
        if (! $form->is_active) {
            throw ValidationException::withMessages(['form' => __('forms.errors.unavailable')]);
        }
        if ($form->status !== 'published') {
            throw ValidationException::withMessages(['form' => __('forms.errors.not_published')]);
        }
        if ($form->starts_at && now()->lt($form->starts_at)) {
            throw ValidationException::withMessages(['form' => __('forms.errors.not_started')]);
        }
        if ($form->ends_at && now()->gt($form->ends_at)) {
            throw ValidationException::withMessages(['form' => __('forms.errors.closed')]);
        }
    }
}
