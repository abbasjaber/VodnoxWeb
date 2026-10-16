<?php

namespace App\Services\Routing;

use App\Models\RoutingSchedule;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Pure matching + validation rules for communication routing schedules.
 *
 * Time semantics (Asia/Baghdad local time):
 *  - The end of a window is EXCLUSIVE: 09:00-17:00 matches 09:00 but not 17:00.
 *  - A window whose end <= start crosses midnight: Sat 22:00-02:00 matches
 *    Sunday 01:00 but not Sunday 02:00.
 */
class ScheduleMatcher
{
    public const MIN_RING = 5;

    public const MAX_RING = 120;

    public const FALLBACK_RING = 20;

    /**
     * Does the schedule cover the given moment (in the schedule's local timezone)?
     */
    public function matches(RoutingSchedule $schedule, ?CarbonInterface $moment = null): bool
    {
        if (! $schedule->is_active) {
            return false;
        }

        $moment ??= now();
        $moment = $moment->copy()->setTimezone($schedule->organization?->timezone ?? 'Asia/Baghdad');

        $weekday = (int) $moment->dayOfWeek;
        $time = $moment->format('H:i:s');

        foreach ($schedule->days as $day) {
            if ($this->dayWindowMatches((int) $day->day, (string) $day->start_time, (string) $day->end_time, $weekday, $time)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does a single day window (which may cross midnight) match the weekday+time?
     */
    public function dayWindowMatches(int $day, string $start, string $end, int $weekday, string $time): bool
    {
        $start = $this->normalizeTime($start);
        $end = $this->normalizeTime($end);

        if ($start === $end) {
            return false;
        }

        $crossesMidnight = $end < $start;

        if (! $crossesMidnight) {
            return $day === $weekday && $time >= $start && $time < $end;
        }

        // Crossing midnight: [day start .. day+1 end)
        if ($day === $weekday) {
            return $time >= $start;
        }

        if (($day + 1) % 7 === $weekday) {
            return $time < $end;
        }

        return false;
    }

    /**
     * Ordered recipient list when the schedule is active at the moment.
     * Simultaneous strategies return all targets; sequential returns the first
     * reachable target first (ordering preserved).
     */
    public function activeTargets(RoutingSchedule $schedule, ?CarbonInterface $moment = null): Collection
    {
        if (! $this->matches($schedule, $moment)) {
            return collect();
        }

        return $schedule->targets->pluck('user')->filter()->values();
    }

    /**
     * Validate the day windows of a schedule definition.
     *
     * @param  array<int, array{day:mixed,start_time:mixed,end_time:mixed}>  $days
     *
     * @throws ValidationException
     */
    public function validateDays(array $days): void
    {
        if (count($days) === 0) {
            throw ValidationException::withMessages([
                'days' => __('vodnox.routing.days_required'),
            ]);
        }

        $errors = [];
        $seen = [];

        foreach ($days as $index => $day) {
            $weekday = (int) ($day['day'] ?? -1);
            $start = $this->normalizeTime((string) ($day['start_time'] ?? ''));
            $end = $this->normalizeTime((string) ($day['end_time'] ?? ''));

            if ($weekday < 0 || $weekday > 6) {
                $errors["days.$index.day"] = __('vodnox.routing.invalid_day');
                continue;
            }

            if ($start === '' || $end === '') {
                $errors["days.$index.start_time"] = __('vodnox.routing.invalid_time');
                continue;
            }

            if ($start === $end) {
                $errors["days.$index.end_time"] = __('vodnox.routing.start_equals_end');
                continue;
            }

            $key = $weekday.':'.$start.':'.$end;
            if (isset($seen[$key])) {
                $errors["days.$index.day"] = __('vodnox.routing.duplicate_day');
                continue;
            }
            $seen[$key] = true;
        }

        // Overlap across the whole set.
        for ($i = 0; $i < count($days); $i++) {
            for ($j = $i + 1; $j < count($days); $j++) {
                if ($this->windowsOverlap(
                    (int) $days[$i]['day'], (string) $days[$i]['start_time'], (string) $days[$i]['end_time'],
                    (int) $days[$j]['day'], (string) $days[$j]['start_time'], (string) $days[$j]['end_time'],
                )) {
                    $errors["days.$j.day"] = __('vodnox.routing.overlapping_windows');
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Whether two day windows overlap on the weekly timeline (handles midnight
     * crossing — including a window that wraps past the end of the week).
     */
    public function windowsOverlap(int $dayA, string $startA, string $endA, int $dayB, string $startB, string $endB): bool
    {
        foreach ($this->expandWindow($dayA, $startA, $endA) as [$as, $ae]) {
            foreach ($this->expandWindow($dayB, $startB, $endB) as [$bs, $be]) {
                if ($as < $be && $bs < $ae) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Validate routing targets: same organization, active, no duplicates.
     *
     * @param  array<int, mixed>  $userIds
     *
     * @throws ValidationException
     */
    public function validateTargets(array $userIds, int $organizationId, int $max = 2): void
    {
        $errors = [];

        $userIds = array_values(array_filter(array_map('intval', $userIds)));

        if (count($userIds) === 0) {
            $errors['targets'] = __('vodnox.routing.targets_required');
        }

        if (count($userIds) > $max) {
            $errors['targets'] = __('vodnox.routing.too_many_targets');
        }

        if (count($userIds) !== count(array_unique($userIds))) {
            $errors['targets'] = __('vodnox.routing.duplicate_target');
        }

        $valid = User::query()
            ->whereIn('id', $userIds)
            ->where('organization_id', $organizationId)
            ->where('active', true)
            ->pluck('id')
            ->all();

        foreach ($userIds as $index => $id) {
            if (! in_array($id, $valid, true)) {
                $errors["targets.$index"] = __('vodnox.routing.invalid_target');
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Validate the ring duration bounds.
     */
    public function validateRingDuration(int|string|null $seconds): int
    {
        $seconds = (int) $seconds;

        if ($seconds < self::MIN_RING || $seconds > self::MAX_RING) {
            throw ValidationException::withMessages([
                'ring_duration' => __('vodnox.routing.invalid_ring_duration'),
            ]);
        }

        return $seconds;
    }

    private function normalizeTime(string $time): string
    {
        $time = trim($time);

        if ($time === '') {
            return '';
        }

        // Accept H:i and H:i:s.
        if (preg_match('/^(\d{1,2}):(\d{2})(:(\d{2}))?$/', $time, $m)) {
            return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[4] ?? 0));
        }

        return '';
    }

    /**
     * Expand a day window into one or two minute intervals on the weekly
     * timeline [0, 7*1440). Windows crossing midnight (end <= start) — or
     * running past the end of the week — are split so overlaps are compared
     * correctly.
     *
     * @return array<int, array{0:int,1:int}>
     */
    private function expandWindow(int $day, string $start, string $end): array
    {
        $start = $this->normalizeTime($start);
        $end = $this->normalizeTime($end);

        $toMinutes = function (string $t): int {
            [$h, $m] = array_map('intval', explode(':', $t));

            return $h * 60 + $m;
        };

        $base = $day * 1440;
        $s = $base + $toMinutes($start ?: '00:00:00');
        $e = $base + $toMinutes($end ?: '00:00:00');

        if ($e <= $s) {
            $e += 1440; // crosses midnight
        }

        $week = 7 * 1440;

        if ($e <= $week) {
            return [[$s, $e]];
        }

        // The window wraps past Saturday midnight into the following week.
        return [[$s, $week], [0, $e - $week]];
    }
}
