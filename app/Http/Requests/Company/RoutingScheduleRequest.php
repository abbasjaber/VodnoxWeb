<?php

namespace App\Http\Requests\Company;

use App\Models\IvrMenu;
use App\Models\RoutingSchedule;
use App\Models\User;
use App\Services\Routing\ScheduleMatcher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class RoutingScheduleRequest extends FormRequest
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
        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'strategy' => ['required', Rule::in(['simultaneous', 'sequential'])],
            'is_active' => ['sometimes', 'boolean'],
            'ring_duration' => ['required', 'integer'],
            'fallback_ring_duration' => ['nullable', 'integer', 'between:5,120'],
            'no_answer_type' => ['required', Rule::in(['voicemail', 'message', 'hangup', 'agent'])],
            'no_answer_target_id' => ['nullable', 'integer'],
            'outside_hours_type' => ['required', Rule::in(['voicemail', 'ivr', 'hangup'])],
            'outside_hours_target_id' => ['nullable', 'integer'],

            'days' => ['required', 'array', 'min:1'],
            'days.*.day' => ['required', 'integer', 'between:0,6'],
            'days.*.start_time' => ['required', 'string'],
            'days.*.end_time' => ['required', 'string'],

            'targets' => ['required', 'array', 'min:1', 'max:2'],
            'targets.*.user_id' => ['required', 'integer'],
            'targets.*.ring_duration' => ['nullable', 'integer', 'between:5,120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $days = array_values(array_filter(
            (array) $this->input('days', []),
            fn ($day) => ! empty($day['start_time']) && ! empty($day['end_time']),
        ));

        $targets = array_values(array_filter(
            (array) $this->input('targets', []),
            fn ($target) => ! empty($target['user_id']),
        ));

        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'days' => $days,
            'targets' => $targets,
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $organization = $this->user()->organization;
            $matcher = app(ScheduleMatcher::class);

            $days = (array) $this->input('days', []);
            $targets = (array) $this->input('targets', []);
            $targetIds = array_map(fn ($t) => (int) ($t['user_id'] ?? 0), $targets);

            $this->runMatcher($v, fn () => $matcher->validateDays($days));
            $this->runMatcher($v, fn () => $matcher->validateTargets($targetIds, (int) $organization->id, 2));
            $this->runMatcher($v, fn () => $matcher->validateRingDuration($this->input('ring_duration')));

            $this->assertNoAnswerTarget($v, $organization->id);
            $this->assertOutsideHoursTarget($v, $organization->id);

            if ($this->boolean('is_active') && count($days) > 0) {
                $this->assertNoActiveOverlap($v, $matcher, (int) $organization->id, $days);
            }
        });
    }

    private function runMatcher(Validator $v, callable $check): void
    {
        try {
            $check();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $v->errors()->add($key, $message);
                }
            }
        }
    }

    private function assertNoAnswerTarget(Validator $v, int $organizationId): void
    {
        if ($this->input('no_answer_type') !== 'agent') {
            return;
        }

        $targetId = (int) $this->input('no_answer_target_id');
        $valid = $targetId && User::query()
            ->whereKey($targetId)
            ->where('organization_id', $organizationId)
            ->where('active', true)
            ->exists();

        if (! $valid) {
            $v->errors()->add('no_answer_target_id', __('company.routing_hint'));
        }
    }

    private function assertOutsideHoursTarget(Validator $v, int $organizationId): void
    {
        if ($this->input('outside_hours_type') !== 'ivr') {
            return;
        }

        $targetId = (int) $this->input('outside_hours_target_id');
        $valid = $targetId && IvrMenu::query()
            ->whereKey($targetId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (! $valid) {
            $v->errors()->add('outside_hours_target_id', __('company.routing_hint'));
        }
    }

    /**
     * Two ACTIVE schedules of the same organization must not have overlapping windows.
     *
     * @param  array<int, array<string, mixed>>  $days
     */
    private function assertNoActiveOverlap(Validator $v, ScheduleMatcher $matcher, int $organizationId, array $days): void
    {
        /** @var RoutingSchedule|null $current */
        $current = $this->route('schedule');

        $others = RoutingSchedule::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->when($current, fn ($q) => $q->whereKeyNot($current->getKey()))
            ->with('days')
            ->get();

        foreach ($others as $other) {
            foreach ($other->days as $otherDay) {
                foreach ($days as $day) {
                    if ($matcher->windowsOverlap(
                        (int) ($day['day'] ?? -1), (string) ($day['start_time'] ?? ''), (string) ($day['end_time'] ?? ''),
                        (int) $otherDay->day, (string) $otherDay->start_time, (string) $otherDay->end_time,
                    )) {
                        $v->errors()->add('days', __('company.schedule_overlap'));

                        return;
                    }
                }
            }
        }
    }
}
