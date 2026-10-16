<?php

namespace Tests\Feature\Telephony;

use App\Models\Call;
use App\Models\CallEvent;
use App\Models\Extension;
use App\Models\Organization;
use App\Services\Telephony\CallEventService;
use Tests\Concerns\BuildsTenant;
use Tests\TestCase;

/**
 * Mapping trustworthy telephony events onto the call log.
 *
 * The endpoint -> organization/employee mapping is read from our own database;
 * the payload's caller-id or organization_id is never trusted. Legs of one call
 * (shared linkedid) collapse into a single row, and out-of-order or late events
 * can neither reopen nor downgrade a call that already ended.
 */
class CallEventMappingTest extends TestCase
{
    use BuildsTenant;

    private Organization $organization;

    private Extension $caller;

    private Extension $callee;

    private CallEventService $calls;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calls = app(CallEventService::class);
        $this->organization = $this->organization();

        $this->caller = $this->extension('1001', 'caller');
        $this->callee = $this->extension('1002', 'callee');
    }

    private function extension(string $number, string $slug, ?Organization $organization = null): Extension
    {
        $organization ??= $this->organization;

        $user = $this->orgUser($organization, 'agent', ['extension' => $number]);

        return Extension::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'extension' => $number,
            'label' => $user->name,
            'status' => 'active',
            'provisioning_status' => 'synced',
            'sip_username' => 'vdx'.$organization->id.'_'.$slug,
            'sip_password' => 'sip-secret-'.$slug,
            'sip_domain' => 'pbx.vodnox.com',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(string $type, array $overrides = []): array
    {
        return array_merge([
            'event_type' => $type,
            'caller_username' => $this->caller->sip_username,
            'callee_username' => $this->callee->sip_username,
            'linkedid' => 'linked-1',
            'sip_call_id' => 'sip-1',
            'occurred_at' => now()->toIso8601String(),
        ], $overrides);
    }

    /*
    |--------------------------------------------------------------------------
    | Lifecycle
    |--------------------------------------------------------------------------
    */

    public function test_a_full_internal_call_becomes_one_row_with_a_real_duration(): void
    {
        $this->calls->apply($this->event('ringing', ['occurred_at' => '2026-10-16T10:00:00Z']));
        $this->calls->apply($this->event('answered', ['occurred_at' => '2026-10-16T10:00:05Z']));
        $this->calls->apply($this->event('ended', [
            'occurred_at' => '2026-10-16T10:01:05Z',
            'billsec' => 60,
            'hangup_cause' => 'normal_clearing',
        ]));

        $call = Call::query()->sole();

        $this->assertSame('ended', $call->status);
        $this->assertSame($this->organization->id, $call->organization_id);
        $this->assertSame($this->callee->user_id, $call->user_id);
        $this->assertSame('1001', $call->caller_extension);
        $this->assertSame('1002', $call->callee_extension);
        $this->assertSame('internal', $call->direction);
        $this->assertSame(60, $call->duration_seconds);
        $this->assertNotNull($call->answered_at);
        $this->assertNotNull($call->ended_at);

        $this->assertSame(3, CallEvent::query()->count());
        $this->assertSame(
            ['ringing', 'answered', 'ended'],
            CallEvent::query()->orderBy('id')->pluck('event_type')->all(),
        );
    }

    public function test_a_terminal_event_alone_never_creates_a_call(): void
    {
        $this->assertNull($this->calls->apply($this->event('ended')));
        $this->assertNull($this->calls->apply($this->event('failed')));

        $this->assertSame(0, Call::query()->count());
    }

    public function test_late_events_cannot_downgrade_a_finished_call(): void
    {
        $this->calls->apply($this->event('ringing'));
        $this->calls->apply($this->event('answered'));
        $this->calls->apply($this->event('ended', ['billsec' => 30]));

        $this->calls->apply($this->event('ringing'));
        $this->calls->apply($this->event('answered'));

        $call = Call::query()->sole();

        $this->assertSame('ended', $call->status);
        $this->assertSame(30, $call->duration_seconds);
        $this->assertSame(3, CallEvent::query()->count());
    }

    public function test_a_failed_call_that_was_ringing_is_recorded_as_unanswered(): void
    {
        $this->calls->apply($this->event('ringing'));
        $this->calls->apply($this->event('failed', ['hangup_cause' => 'no_answer']));

        $call = Call::query()->sole();

        $this->assertSame('unanswered', $call->status);
        $this->assertNotNull($call->ended_at);
    }

    /*
    |--------------------------------------------------------------------------
    | Leg grouping & duration
    |--------------------------------------------------------------------------
    */

    public function test_legs_sharing_a_linkedid_collapse_into_one_call(): void
    {
        $this->calls->apply($this->event('ringing', ['sip_call_id' => 'leg-a']));
        $this->calls->apply($this->event('ringing', ['sip_call_id' => 'leg-b']));
        $this->calls->apply($this->event('answered', ['sip_call_id' => 'leg-b']));

        $this->assertSame(1, Call::query()->count());
        $this->assertSame('linked-1', Call::query()->sole()->external_call_id);
    }

    public function test_without_a_linkedid_the_sip_call_id_groups_the_legs(): void
    {
        $this->calls->apply($this->event('ringing', ['linkedid' => null]));
        $this->calls->apply($this->event('answered', ['linkedid' => null]));

        $this->assertSame(1, Call::query()->count());
        $this->assertSame('sip-1', Call::query()->sole()->external_call_id);
    }

    public function test_duration_falls_back_to_the_elapsed_time_when_unreported(): void
    {
        $this->calls->apply($this->event('answered', ['occurred_at' => '2026-10-16T10:00:00Z']));
        $this->calls->apply($this->event('ended', ['occurred_at' => '2026-10-16T10:00:42Z']));

        $this->assertSame(42, Call::query()->sole()->duration_seconds);
    }

    public function test_an_event_without_any_group_key_is_ignored(): void
    {
        $this->assertNull($this->calls->apply($this->event('ringing', ['linkedid' => null, 'sip_call_id' => null])));

        $this->assertSame(0, Call::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Trust & isolation
    |--------------------------------------------------------------------------
    */

    public function test_an_event_from_an_unknown_endpoint_is_ignored(): void
    {
        $payload = $this->event('ringing', [
            'caller_username' => 'vdx999_nobody',
            'callee_username' => 'vdx999_nobody',
        ]);

        $this->assertNull($this->calls->apply($payload));
        $this->assertSame(0, Call::query()->count());
    }

    public function test_a_spoofed_organization_id_cannot_move_a_call_across_tenants(): void
    {
        $other = $this->organization();

        $this->calls->apply($this->event('ringing', ['organization_id' => $other->id]));

        $this->assertSame($this->organization->id, Call::query()->sole()->organization_id);
    }

    public function test_identical_numbers_in_two_organizations_stay_isolated(): void
    {
        $other = $this->organization();
        $otherCaller = $this->extension('1001', 'othercaller', $other);
        $otherCallee = $this->extension('1002', 'othercallee', $other);

        $this->calls->apply($this->event('ringing'));

        $this->calls->apply([
            'event_type' => 'ringing',
            'caller_username' => $otherCaller->sip_username,
            'callee_username' => $otherCallee->sip_username,
            'linkedid' => 'linked-1',
            'occurred_at' => now()->toIso8601String(),
        ]);

        $this->assertSame(2, Call::query()->count());
        $this->assertSame(1, Call::query()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(1, Call::query()->where('organization_id', $other->id)->count());
    }
}
