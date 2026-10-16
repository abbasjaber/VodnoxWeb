<?php

declare(strict_types=1);

namespace Vodnox\Telephony\Events;

/**
 * Turns raw AMI frames into the call lifecycle events Laravel understands.
 *
 * Rules that matter:
 *  - only channels provisioned by VODNOX (and, when reported, only channels in
 *    a `vodnox_org_*` context) are tracked, so the legacy `vodnox-test` context
 *    and the old 1001/1002 endpoints can never produce events;
 *  - all legs of one call share a `linkedid` and collapse into a single call;
 *    the originating leg is the one whose `Uniqueid` equals its `Linkedid`;
 *  - a call is only closed when every tracked leg has hung up, so the first
 *    hangup of a bridged call does not close the log entry early;
 *  - nothing is emitted for a `linkedid` that was never seen as a new channel,
 *    so a restart mid-call cannot invent a call.
 *
 * The emitted vocabulary matches the receiver: ringing, answered, ended,
 * failed and busy.
 */
final class CallTracker
{
    private const RING_STATES = [4, 5];      // Ring, Ringing
    private const UP_STATE = 6;
    private const BUSY_STATE = 7;

    private const CONTEXT_PATTERN = '/^vodnox_org_[0-9]+$/';

    /** @var array<int, string> */
    private const CAUSES = [
        0 => 'not_defined',
        1 => 'unallocated_number',
        3 => 'no_route',
        16 => 'normal_clearing',
        17 => 'user_busy',
        18 => 'user_response_timeout',
        19 => 'no_answer',
        20 => 'subscriber_absent',
        21 => 'call_rejected',
        22 => 'number_changed',
        27 => 'destination_out_of_order',
        28 => 'invalid_number_format',
        31 => 'normal_unspecified',
        34 => 'no_circuit',
        38 => 'network_out_of_order',
        41 => 'temporary_failure',
        58 => 'bearer_capability_not_available',
        88 => 'incompatible_destination',
        102 => 'timer_expired',
        127 => 'interworking',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $calls = [];

    /**
     * Drop all state (used after an AMI reconnect, where earlier frames are lost
     * and reopening them would corrupt the log).
     */
    public function forget(): void
    {
        $this->calls = [];
    }

    /**
     * @param  array<string, string>  $frame
     * @return array<int, array<string, mixed>>  zero or more events to deliver
     */
    public function ingest(array $frame, ?float $now = null): array
    {
        $now ??= microtime(true);

        return match (strtolower(trim((string) ($frame['Event'] ?? '')))) {
            'newchannel' => $this->onNewChannel($frame, $now),
            'newstate' => $this->onNewState($frame, $now),
            'hangup' => $this->onHangup($frame, $now),
            default => [],
        };
    }

    /**
     * @param  array<string, string>  $frame
     * @return array<int, array<string, mixed>>
     */
    private function onNewChannel(array $frame, float $now): array
    {
        $channel = (string) ($frame['Channel'] ?? '');

        if (! ChannelIdentity::isManaged($channel) || ! $this->contextIsAllowed($frame['Context'] ?? null)) {
            return [];
        }

        $linkedid = trim((string) ($frame['Linkedid'] ?? ''));

        if ($linkedid === '') {
            return [];
        }

        $this->ensure($linkedid, $now);
        $group = &$this->calls[$linkedid];

        $username = ChannelIdentity::parse($channel)['username'] ?? null;
        $uniqueid = trim((string) ($frame['Uniqueid'] ?? ''));

        $group['legs'][$channel] = [
            'username' => $username,
            'hungup' => false,
        ];

        if ($uniqueid !== '' && $uniqueid === $linkedid) {
            // The originating leg: Uniqueid == Linkedid.
            $group['originator'] = $channel;
            $group['caller_username'] = $username;
            $group['caller_number'] ??= $this->number($frame['CallerIDNum'] ?? null);
            $group['callee_number'] ??= $this->number($frame['Exten'] ?? null);
        } elseif ($group['originator'] === null) {
            // A leg created by Dial: the peer being called.
            $group['callee_username'] ??= $username;
        }

        return [];
    }

    /**
     * @param  array<string, string>  $frame
     * @return array<int, array<string, mixed>>
     */
    private function onNewState(array $frame, float $now): array
    {
        $channel = (string) ($frame['Channel'] ?? '');
        $linkedid = trim((string) ($frame['Linkedid'] ?? ''));

        if ($linkedid === '' || ! isset($this->calls[$linkedid])) {
            return [];
        }

        $group = &$this->calls[$linkedid];

        if ($group['terminal']) {
            return [];
        }

        $state = $this->state($frame);
        $events = [];

        if ($channel !== $group['originator'] && in_array($state, self::RING_STATES, true) && ! $group['ringing']) {
            $group['ringing'] = true;
            $group['callee_username'] ??= ChannelIdentity::parse($channel)['username'] ?? null;
            $events[] = $this->event($group, 'ringing', $now);
        }

        if ($state === self::UP_STATE && ! $group['answered']) {
            $group['answered'] = true;
            $group['answered_at'] = $now;
            $events[] = $this->event($group, 'answered', $now);
        }

        if ($state === self::BUSY_STATE && ! $group['answered']) {
            $group['terminal'] = true;
            $events[] = $this->event($group, 'busy', $now, 0, 'user_busy');
            unset($this->calls[$linkedid]);
        }

        return $events;
    }

    /**
     * @param  array<string, string>  $frame
     * @return array<int, array<string, mixed>>
     */
    private function onHangup(array $frame, float $now): array
    {
        $channel = (string) ($frame['Channel'] ?? '');
        $linkedid = trim((string) ($frame['Linkedid'] ?? ''));

        if ($linkedid === '' || ! isset($this->calls[$linkedid])) {
            return [];
        }

        $group = &$this->calls[$linkedid];

        if ($group['terminal']) {
            unset($this->calls[$linkedid]);

            return [];
        }

        if (isset($group['legs'][$channel])) {
            $group['legs'][$channel]['hungup'] = true;
        }

        $cause = $this->cause($frame);

        if (! $group['answered'] && $cause !== null && $cause !== 'normal_clearing') {
            // Keep the most meaningful cause of the leg that refused to answer.
            $group['cause_hint'] ??= $cause;
        }

        if (! $this->allLegsDown($group)) {
            return [];
        }

        $reason = $group['answered'] ? $cause : ($group['cause_hint'] ?? $cause);

        if ($group['answered']) {
            $duration = $group['answered_at'] !== null ? max(0, (int) round($now - $group['answered_at'])) : null;
            $event = $this->event($group, 'ended', $now, $duration, $reason);
        } else {
            $event = $this->event($group, 'failed', $now, 0, $reason);
        }

        unset($this->calls[$linkedid]);

        return [$event];
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function event(array $group, string $type, float $now, ?int $duration = null, ?string $cause = null): array
    {
        $event = [
            'event_id' => self::eventId($group['linkedid'], $type),
            'event_type' => $type,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $now),
            'linkedid' => $group['linkedid'],
            'direction' => 'internal',
            'caller_username' => $group['caller_username'],
            'callee_username' => $group['callee_username'],
            'caller_number' => $group['caller_number'],
            'callee_number' => $group['callee_number'],
        ];

        if ($cause !== null) {
            $event['hangup_cause'] = $cause;
        }

        if ($duration !== null) {
            $event['duration_seconds'] = $duration;
        }

        return array_filter($event, static fn ($value): bool => $value !== null);
    }

    /**
     * A stable id so a re-delivered spool entry is idempotent on the receiver.
     */
    public static function eventId(string $linkedid, string $type): string
    {
        return 'evt-'.substr(hash('sha256', $linkedid.'|'.$type), 0, 40);
    }

    private function ensure(string $linkedid, float $now): void
    {
        if (isset($this->calls[$linkedid])) {
            return;
        }

        $this->calls[$linkedid] = [
            'linkedid' => $linkedid,
            'legs' => [],
            'originator' => null,
            'caller_username' => null,
            'callee_username' => null,
            'caller_number' => null,
            'callee_number' => null,
            'ringing' => false,
            'answered' => false,
            'terminal' => false,
            'answered_at' => null,
            'cause_hint' => null,
            'started_at' => $now,
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function allLegsDown(array $group): bool
    {
        if ($group['legs'] === []) {
            return false;
        }

        foreach ($group['legs'] as $leg) {
            if ($leg['hungup'] === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $frame
     */
    private function state(array $frame): int
    {
        $state = trim((string) ($frame['ChannelState'] ?? ''));

        if ($state !== '' && is_numeric($state)) {
            return (int) $state;
        }

        return match (strtolower(trim((string) ($frame['ChannelStateDesc'] ?? '')))) {
            'ring' => 4,
            'ringing' => 5,
            'up' => 6,
            'busy' => 7,
            default => -1,
        };
    }

    /**
     * @param  array<string, string>  $frame
     */
    private function cause(array $frame): ?string
    {
        $code = trim((string) ($frame['Cause'] ?? ''));

        if ($code !== '' && is_numeric($code) && isset(self::CAUSES[(int) $code])) {
            return self::CAUSES[(int) $code];
        }

        $text = trim((string) ($frame['Cause-txt'] ?? ''));

        return $text === '' ? null : strtolower(str_replace([' ', '-'], '_', $text));
    }

    private function number(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^[0-9*#]{2,10}$/', $value) === 1 ? $value : null;
    }

    /**
     * Only channels in an organization context are trusted; a channel that
     * appears in a shared context is ignored rather than logged.
     */
    private function contextIsAllowed(mixed $context): bool
    {
        if (! is_string($context) || $context === '') {
            return true;
        }

        return preg_match(self::CONTEXT_PATTERN, $context) === 1;
    }
}
