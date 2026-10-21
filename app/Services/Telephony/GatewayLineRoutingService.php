<?php

namespace App\Services\Telephony;

use App\Models\Extension;
use App\Models\GatewayLine;
use App\Models\GatewayLineDateException;
use App\Models\GatewayLineRoute;
use App\Services\Routing\ScheduleMatcher;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The single source of truth for "where does a call arriving on this SIM go".
 *
 * The very same rows are used twice:
 *  - here, to answer the administrator's preview button;
 *  - inside the generated Asterisk dialplan, which is what actually decides at
 *    call time (so a closed browser or a stopped cron changes nothing).
 *
 * Priority is fixed and public, in this exact order:
 *   1. the temporary permanent forward, while it is switched on;
 *   2. a dated exception whose inclusive range covers the moment;
 *   3. the weekly rules — a weekend day, then the business-hours windows;
 *   4. the default destination when no rule decided anything.
 *
 * When the destination a rule points at is missing or disabled, nothing is
 * guessed: the configured fallback is used if there is one, otherwise the call
 * is refused with a readable reason. A destination can never be another
 * organization's extension.
 */
class GatewayLineRoutingService
{
    /** The reason codes the UI and the agent both understand. */
    public const REASON_NO_DESTINATION = 'no_destination';

    public const REASON_DESTINATION_UNAVAILABLE = 'destination_unavailable';

    public function __construct(private readonly ScheduleMatcher $matcher) {}

    /**
     * The routing row of a line, created on first use.
     *
     * A line created before this feature existed is migrated with its current
     * destination as the default, so nothing changes for it silently.
     */
    public function routeFor(GatewayLine $line): GatewayLineRoute
    {
        return GatewayLineRoute::firstOrCreate(
            ['gateway_line_id' => $line->getKey()],
            [
                'organization_id' => $line->organization_id,
                'default_destination_extension_id' => $line->inbound_extension_id,
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Decision
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve the destination that applies at $moment.
     *
     * @return array{
     *     rule: string,
     *     matched_rule: string,
     *     destination: ?Extension,
     *     destination_extension_id: ?int,
     *     destination_username: ?string,
     *     reason: ?string,
     *     exception_id: ?int,
     *     exception_label: ?string,
     *     timezone: string,
     *     moment: CarbonInterface,
     *     in_business_hours: bool,
     *     weekday: int
     * }
     */
    public function resolve(GatewayLineRoute $route, ?CarbonInterface $moment = null): array
    {
        $timezone = $route->timezone();
        $moment = ($moment ?? now())->copy()->setTimezone($timezone);

        $route->loadMissing([
            'businessHours', 'weekendDays', 'dateExceptions.destination',
            'defaultDestination', 'outsideHoursDestination', 'weekendDestination',
            'fallbackDestination', 'temporaryForwardDestination', 'organization',
        ]);

        $weekday = (int) $moment->dayOfWeek;
        $time = $moment->format('H:i:s');
        $date = $moment->format('Y-m-d');

        $matchedRule = 'default';
        $candidate = $route->defaultDestination;
        $exception = null;
        $inBusinessHours = false;

        if ($route->temporary_forward_enabled) {
            $matchedRule = 'temporary_forward';
            $candidate = $route->temporaryForwardDestination;
        } else {
            $exception = $this->matchingException($route, $date);

            if ($exception !== null) {
                $matchedRule = 'date_exception';
                $candidate = $exception->destination;
            } else {
                $weekendDays = $route->weekendDays->pluck('day')->map(fn ($day) => (int) $day)->all();
                $isWeekend = in_array($weekday, $weekendDays, true);

                $windows = $route->businessHours;

                // "Outside hours" only exists relative to defined hours: with no
                // window at all, a working day is not "outside" anything, so no
                // weekly rule decides and the default destination applies. This
                // is exactly what the generated dialplan does, so the preview
                // and the live call cannot disagree.
                if ($isWeekend) {
                    $matchedRule = 'weekend';
                    // A weekend with no destination of its own reuses the
                    // after-hours destination; both empty is a real gap.
                    $candidate = $route->weekend_destination_extension_id
                        ? $route->weekendDestination
                        : $route->outsideHoursDestination;
                } elseif ($windows->isNotEmpty()) {
                    $inBusinessHours = $windows->contains(
                        fn ($window) => $this->matcher->dayWindowMatches(
                            (int) $window->day,
                            (string) $window->start_time,
                            (string) $window->end_time,
                            $weekday,
                            $time,
                        ),
                    );

                    $matchedRule = $inBusinessHours ? 'business_hours' : 'outside_hours';
                    $candidate = $inBusinessHours
                        ? $route->defaultDestination
                        : $route->outsideHoursDestination;
                }
            }
        }

        $fallback = $route->fallbackDestination;
        $destination = $this->usable($candidate, $route) ? $candidate : null;
        $rule = $matchedRule;
        $reason = null;

        if ($destination === null) {
            if ($this->usable($fallback, $route)) {
                $destination = $fallback;
                $rule = 'fallback';
            } else {
                $rule = 'none';
                $reason = $candidate === null
                    ? self::REASON_NO_DESTINATION
                    : self::REASON_DESTINATION_UNAVAILABLE;
            }
        }

        return [
            'rule' => $rule,
            'matched_rule' => $matchedRule,
            'destination' => $destination,
            'destination_extension_id' => $destination?->getKey(),
            'destination_username' => $destination?->sip_username,
            'reason' => $reason,
            'exception_id' => $exception?->getKey(),
            'exception_label' => $exception?->label,
            'timezone' => $timezone,
            'moment' => $moment,
            'in_business_hours' => $inBusinessHours,
            'weekday' => $weekday,
        ];
    }

    /**
     * The effective SIP username a rule maps to, with the fallback already
     * applied — so the agent only has to pick the rule, never re-decide
     * usability (that would be a second source of truth).
     */
    public function effectiveUsername(?Extension $candidate, GatewayLineRoute $route): ?string
    {
        if ($this->usable($candidate, $route)) {
            return (string) $candidate->sip_username;
        }

        $fallback = $route->fallbackDestination;

        return $this->usable($fallback, $route) ? (string) $fallback->sip_username : null;
    }

    /**
     * A destination is usable only when it is an active, dialable extension of
     * the *same* organization as the line.
     */
    public function usable(?Extension $destination, GatewayLineRoute $route): bool
    {
        return $destination instanceof Extension
            && (int) $destination->organization_id === (int) $route->organization_id
            && $destination->status === 'active'
            && (bool) $destination->sip_username;
    }

    /*
    |--------------------------------------------------------------------------
    | Agent payload
    |--------------------------------------------------------------------------
    */

    /**
     * The routing block handed to the Asterisk agent.
     *
     * It carries the full rule set with *resolved usernames* (never a database
     * id, never a secret), so the dialplan is regenerated from the same rows
     * the preview uses.
     *
     * A rule whose own destination is unusable still keeps its slot: it is
     * compiled with the configured fallback when there is one, otherwise with an
     * empty username, which the agent compiles as "this rule matched and the
     * call is refused". It is never dropped, because dropping it would let the
     * call fall through to a lower-priority rule and disagree with resolve().
     *
     * @return array<string, mixed>
     */
    public function payload(GatewayLineRoute $route): array
    {
        $route->loadMissing([
            'businessHours', 'weekendDays', 'dateExceptions.destination',
            'defaultDestination', 'outsideHoursDestination', 'weekendDestination',
            'fallbackDestination', 'temporaryForwardDestination', 'organization',
        ]);

        $exceptions = [];

        foreach ($route->dateExceptions as $exception) {
            $exceptions[] = [
                'starts_on' => $exception->starts_on?->format('Y-m-d'),
                'ends_on' => $exception->ends_on?->format('Y-m-d'),
                // Null means "matched, but neither the exception nor the
                // fallback is usable": the agent refuses instead of falling
                // through to weekend/hours/default.
                'destination_username' => $this->effectiveUsername($exception->destination, $route),
            ];
        }

        return [
            'timezone' => $route->timezone(),
            // null when the forward is off; an object (possibly with a null
            // destination) when it is on, so an unusable forward still wins and
            // is refused instead of being silently skipped.
            'temporary_forward' => $route->temporary_forward_enabled
                ? ['destination_username' => $this->effectiveUsername($route->temporaryForwardDestination, $route)]
                : null,
            'exceptions' => $exceptions,
            'weekend_days' => $route->weekendDays->pluck('day')->map(fn ($day) => (int) $day)->values()->all(),
            'business_hours' => $route->businessHours->map(fn ($window) => [
                'day' => (int) $window->day,
                'start' => substr((string) $window->start_time, 0, 5),
                'end' => substr((string) $window->end_time, 0, 5),
            ])->values()->all(),
            'in_hours_destination_username' => $this->effectiveUsername($route->defaultDestination, $route),
            'outside_hours_destination_username' => $this->effectiveUsername($route->outsideHoursDestination, $route),
            'weekend_destination_username' => $this->effectiveUsername(
                $route->weekend_destination_extension_id ? $route->weekendDestination : $route->outsideHoursDestination,
                $route,
            ),
            'default_destination_username' => $this->effectiveUsername($route->defaultDestination, $route),
            'fallback_destination_username' => $this->effectiveUsername($route->fallbackDestination, $route),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Validate the weekly windows. Overlaps are refused instead of being
     * resolved by an implicit "whichever wins" rule.
     *
     * The shape is the same one the rows are stored in (`day`, `start_time`,
     * `end_time`), so the validator and the persisted data can never drift.
     *
     * @param  array<int, array<string, mixed>>  $windows
     *
     * @throws ValidationException
     */
    public function validateBusinessHours(array $windows): void
    {
        $errors = [];
        $seen = [];

        foreach ($windows as $index => $window) {
            $day = (int) ($window['day'] ?? -1);
            $start = $this->normalizeTime((string) ($window['start_time'] ?? ''));
            $end = $this->normalizeTime((string) ($window['end_time'] ?? ''));

            if ($day < 0 || $day > 6) {
                $errors["business_hours.$index.day"] = __('vodnox.routing.invalid_day');
                continue;
            }

            if ($start === '' || $end === '') {
                $errors["business_hours.$index.start"] = __('vodnox.routing.invalid_time');
                continue;
            }

            if ($start === $end) {
                $errors["business_hours.$index.end"] = __('vodnox.routing.start_equals_end');
                continue;
            }

            $key = $day.':'.$start.':'.$end;

            if (isset($seen[$key])) {
                $errors["business_hours.$index.day"] = __('vodnox.routing.duplicate_day');
                continue;
            }

            $seen[$key] = true;
        }

        for ($i = 0; $i < count($windows); $i++) {
            for ($j = $i + 1; $j < count($windows); $j++) {
                if ($this->matcher->windowsOverlap(
                    (int) ($windows[$i]['day'] ?? -1),
                    (string) ($windows[$i]['start_time'] ?? ''),
                    (string) ($windows[$i]['end_time'] ?? ''),
                    (int) ($windows[$j]['day'] ?? -1),
                    (string) ($windows[$j]['start_time'] ?? ''),
                    (string) ($windows[$j]['end_time'] ?? ''),
                )) {
                    $errors["business_hours.$j.day"] = __('vodnox.routing.overlapping_windows');
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Validate the dated exceptions: a real, non-inverted range and no two
     * ranges covering the same day.
     *
     * @param  array<int, array<string, mixed>>  $exceptions
     *
     * @throws ValidationException
     */
    public function validateExceptions(array $exceptions): void
    {
        $errors = [];
        $ranges = [];

        foreach ($exceptions as $index => $exception) {
            $starts = (string) ($exception['starts_on'] ?? '');
            $ends = (string) ($exception['ends_on'] ?? '');

            if ($this->normalizeDate($starts) === '' || $this->normalizeDate($ends) === '') {
                $errors["exceptions.$index.starts_on"] = __('vodnox.routing.invalid_date');
                continue;
            }

            if ($ends < $starts) {
                $errors["exceptions.$index.ends_on"] = __('vodnox.routing.invalid_date_range');
                continue;
            }

            $ranges[] = ['index' => $index, 'start' => $starts, 'end' => $ends];
        }

        for ($i = 0; $i < count($ranges); $i++) {
            for ($j = $i + 1; $j < count($ranges); $j++) {
                if ($ranges[$i]['start'] <= $ranges[$j]['end'] && $ranges[$j]['start'] <= $ranges[$i]['end']) {
                    $errors["exceptions.{$ranges[$j]['index']}.starts_on"] = __('vodnox.routing.overlapping_exceptions');
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * A destination must be an active extension of the same organization.
     *
     * @throws ValidationException
     */
    public function validateDestination(mixed $extensionId, int $organizationId, string $field): void
    {
        if ($extensionId === null || $extensionId === '') {
            return;
        }

        $valid = Extension::query()
            ->whereKey((int) $extensionId)
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNotNull('sip_username')
            ->exists();

        if (! $valid) {
            throw ValidationException::withMessages([
                $field => __('company.destination_not_in_organization'),
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function matchingException(GatewayLineRoute $route, string $date): ?GatewayLineDateException
    {
        return $route->dateExceptions->first(function (GatewayLineDateException $exception) use ($date) {
            $starts = $exception->starts_on?->format('Y-m-d');
            $ends = $exception->ends_on?->format('Y-m-d');

            return $starts !== null && $ends !== null && $date >= $starts && $date <= $ends;
        });
    }

    private function normalizeTime(string $time): string
    {
        $time = trim($time);

        if (! preg_match('/^(\d{1,2}):(\d{2})(:(\d{2}))?$/', $time, $matches)) {
            return '';
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        if ($hours > 23 || $minutes > 59) {
            return '';
        }

        return sprintf('%02d:%02d:%02d', $hours, $minutes, (int) ($matches[4] ?? 0));
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);

        $validator = Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']]);

        return $validator->fails() ? '' : $date;
    }
}
