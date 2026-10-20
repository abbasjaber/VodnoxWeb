<?php

namespace App\Services\Telephony;

use App\Models\Call;
use App\Models\CallEvent;
use App\Models\Extension;
use App\Models\GatewayLine;
use Illuminate\Support\Carbon;

/**
 * Applies trustworthy telephony events to the call log.
 *
 * The mapping from a SIP endpoint to an organization/employee is derived from
 * our own database (extensions.sip_username), never from request headers, so a
 * spoofed Caller-ID or organization_id cannot move a call across tenants.
 *
 * Legs of the same call share an `linkedid`; grouping on it prevents two legs
 * from being logged as two independent calls, and out-of-order or late events
 * cannot reopen or downgrade a call that already ended.
 */
class CallEventService
{
    /** @var array<int, string> */
    private const STATUS_ORDER = [
        'ringing' => 0,
        'answered' => 1,
        'ended' => 2,
        'failed' => 2,
        'busy' => 2,
        'unanswered' => 2,
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function apply(array $payload): ?Call
    {
        $type = strtolower(trim((string) ($payload['event_type'] ?? '')));

        if (! array_key_exists($type, self::STATUS_ORDER)) {
            return null;
        }

        $caller = $this->resolveIdentity($payload['caller_username'] ?? null);
        $callee = $this->resolveIdentity($payload['callee_username'] ?? null);

        // The organization comes from the resolved endpoint, not the payload.
        $organizationId = $callee['organization_id'] ?? $caller['organization_id'] ?? null;

        if (! $organizationId) {
            // Unknown endpoints: nothing trustworthy to attach the event to.
            return null;
        }

        $groupKey = trim((string) ($payload['linkedid'] ?? $payload['sip_call_id'] ?? ''));

        if ($groupKey === '') {
            return null;
        }

        $occurredAt = $this->occurredAt($payload);

        $call = Call::query()
            ->where('organization_id', $organizationId)
            ->where('external_call_id', $groupKey)
            ->first();

        if (! $call) {
            // Do not create a call row from a terminal event alone.
            if (in_array($type, ['ended', 'failed'], true)) {
                return null;
            }

            $call = new Call([
                'organization_id' => $organizationId,
                'external_call_id' => $groupKey,
                'sip_call_id' => $this->stringOrNull($payload['sip_call_id'] ?? null),
                'user_id' => $callee['user_id'] ?? null,
                'direction' => $this->direction($payload, $caller, $callee),
                'caller_number' => $this->stringOrNull($payload['caller_number'] ?? null),
                'callee_number' => $this->stringOrNull($payload['callee_number'] ?? null),
                'caller_extension' => $caller['extension'] ?? null,
                'callee_extension' => $callee['extension'] ?? null,
                'status' => 'ringing',
                'started_at' => $occurredAt,
            ]);
        } else {
            // Ignore regressions from out-of-order events.
            if ($this->isTerminal($call->status)) {
                return $call;
            }

            if (self::STATUS_ORDER[$type] < (self::STATUS_ORDER[$call->status] ?? -1)) {
                return $call;
            }
        }

        $this->applyState($call, $type, $occurredAt, $payload);
        $call->save();

        CallEvent::create([
            'call_id' => $call->getKey(),
            'event_type' => $type,
            'data' => [
                'caller_username' => $payload['caller_username'] ?? null,
                'callee_username' => $payload['callee_username'] ?? null,
                'hangup_cause' => $payload['hangup_cause'] ?? null,
                'linkedid' => $payload['linkedid'] ?? null,
            ],
            'occurred_at' => $occurredAt,
        ]);

        return $call;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyState(Call $call, string $type, Carbon $occurredAt, array $payload): void
    {
        switch ($type) {
            case 'ringing':
                $call->status = 'ringing';
                $call->started_at ??= $occurredAt;
                break;

            case 'answered':
                $call->status = 'answered';
                $call->started_at ??= $occurredAt;
                $call->answered_at ??= $occurredAt;
                break;

            case 'ended':
            case 'failed':
                $call->status = $type === 'failed' ? ($call->status === 'ringing' ? 'unanswered' : 'failed') : 'ended';
                $call->ended_at = $occurredAt;
                $call->duration_seconds = $this->duration($payload, $call, $occurredAt);
                break;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function duration(array $payload, Call $call, Carbon $occurredAt): ?int
    {
        $reported = $payload['duration_seconds'] ?? $payload['billsec'] ?? null;

        if (is_numeric($reported)) {
            return max(0, (int) $reported);
        }

        $from = $call->answered_at ?? $call->started_at;

        return $from ? max(0, (int) $from->diffInSeconds($occurredAt)) : null;
    }

    /**
     * Map a SIP username to the tenant that owns it.
     *
     * Both managed identities are resolved from our own database: an employee
     * extension (vdx…) or a GSM gateway line (vdg…). A gateway line belongs to
     * the organization that owns the device, so an inbound mobile call is
     * attached to the right tenant — and the external Caller-ID never decides it.
     *
     * @return array{organization_id: int, user_id: ?int, extension: ?string, gateway: bool}|null
     */
    private function resolveIdentity(mixed $sipUsername): ?array
    {
        if (! is_string($sipUsername) || $sipUsername === '') {
            return null;
        }

        $extension = Extension::query()->where('sip_username', $sipUsername)->first();

        if ($extension) {
            return [
                'organization_id' => (int) $extension->organization_id,
                'user_id' => $extension->user_id ? (int) $extension->user_id : null,
                'extension' => $extension->extension,
                'gateway' => false,
            ];
        }

        $line = GatewayLine::query()->where('sip_username', $sipUsername)->first();

        if ($line) {
            return [
                'organization_id' => (int) $line->organization_id,
                'user_id' => null,
                'extension' => null,
                'gateway' => true,
            ];
        }

        return null;
    }

    private function isTerminal(?string $status): bool
    {
        return in_array($status, ['ended', 'failed', 'busy', 'unanswered'], true);
    }

    /**
     * The direction is derived from trusted ownership: a leg that starts on a
     * gateway line is inbound (a mobile call arriving), and a leg that ends on
     * one would be outbound. The reported value is used only as a fallback.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $caller
     * @param  array<string, mixed>|null  $callee
     */
    private function direction(array $payload, ?array $caller, ?array $callee): string
    {
        if ($caller['gateway'] ?? false) {
            return 'inbound';
        }

        if ($callee['gateway'] ?? false) {
            return 'outbound';
        }

        $direction = (string) ($payload['direction'] ?? 'internal');

        return in_array($direction, ['inbound', 'outbound', 'internal'], true) ? $direction : 'internal';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function occurredAt(array $payload): Carbon
    {
        $raw = $payload['occurred_at'] ?? null;

        if (is_string($raw) && $raw !== '') {
            try {
                // Datetimes are persisted as application-wall-clock values, so
                // an offset-bearing timestamp (e.g. "...Z") must be normalised
                // before it is compared with a value read back from the row;
                // otherwise the elapsed duration is skewed by the UTC offset.
                return Carbon::parse($raw)->setTimezone(config('app.timezone'));
            } catch (\Throwable) {
                // Fall through to now().
            }
        }

        return now();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 64);
    }
}
