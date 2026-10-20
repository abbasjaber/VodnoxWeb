<?php

namespace Tests\Feature\Telephony;

use App\Contracts\Telephony\TelephonyGateway;
use App\Jobs\ProvisionTelephonyJob;
use App\Models\Call;
use App\Models\Extension;
use App\Models\GatewayDevice;
use App\Models\Organization;
use App\Models\User;
use App\Services\Telephony\CallEventService;
use App\Services\Telephony\GatewayProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenant;
use Tests\Support\FakeTelephonyGateway;
use Tests\TestCase;

/**
 * Behavioural coverage for GSM gateway line provisioning.
 *
 * Verified here (never by inspecting strings):
 *   - two organizations may both use "1001" and still get separate lines,
 *     separate SIP identities and separate inbound paths;
 *   - the SIP identity is globally unique and is never the employee number;
 *   - the SIP password is encrypted at rest, hidden from JSON and never written
 *     into a job payload, a provisioning_jobs row or an audit trail;
 *   - a line is never pushed when its inbound destination is unusable, and an
 *     unusable destination is recorded with a reason instead of silently
 *     switching a working line off;
 *   - enable/disable keeps the identity and re-provisions for real on re-enable;
 *   - capability negotiation makes an old agent ask for a service update rather
 *     than reporting a fake "synced";
 *   - a missing/stale monitoring reading is "unknown", never "disconnected";
 *   - the concurrency lock is keyed on the resource, not on the revision, and a
 *     late retry can never write an older state back;
 *   - an inbound mobile call is attributed to the tenant that owns the gateway
 *     line, and the external Caller-ID never decides it.
 */
class GatewayProvisioningTest extends TestCase
{
    use BuildsTenant;

    private Organization $organization;

    private User $admin;

    private FakeTelephonyGateway $agent;

    private GatewayProvisioningService $gateways;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic classic SIP/UDP settings, deliberately unlike the WSS /
        // provisioning URLs so a mistake would be visible.
        config([
            'integrations.telephony.gateway_sip_host' => 'sip.gw.test',
            'integrations.telephony.gateway_sip_port' => 5060,
            'integrations.telephony.gateway_sip_transport' => 'udp',
            'integrations.telephony.gateway_realm' => 'gw.test',
            'integrations.telephony.gateway_inbound_start' => 5000,
        ]);

        Queue::fake();

        $this->organization = $this->organization();
        $this->admin = $this->orgAdmin($this->organization);

        $this->agent = new FakeTelephonyGateway;
        $this->app->instance(TelephonyGateway::class, $this->agent);

        $this->gateways = app(GatewayProvisioningService::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function destination(?Organization $organization = null, string $number = '1001'): Extension
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
            'sip_username' => 'vdx'.$organization->id.'_'.Str::lower(Str::random(10)),
            'sip_password' => 'sip-'.Str::random(24),
            'sip_domain' => 'pbx.vodnox.com',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $lines
     */
    private function createDevice(?array $lines = null, ?Organization $organization = null): GatewayDevice
    {
        $organization ??= $this->organization;

        $lines ??= [[
            'label' => 'SIM 1',
            'sim_number' => '07701111111',
            'inbound_extension_id' => $this->destination($organization)->id,
            'enabled' => true,
        ]];

        return $this->gateways->createDevice(
            $organization,
            $this->admin->id,
            ['label' => 'GoIP', 'model' => 'goip-1'],
            $lines,
        );
    }

    private function lastGatewayJob(): ProvisionTelephonyJob
    {
        $captured = null;

        Queue::assertPushed(ProvisionTelephonyJob::class, function (ProvisionTelephonyJob $job) use (&$captured) {
            $captured = $job;

            return true;
        });

        $this->assertNotNull($captured, 'No provisioning job was dispatched.');

        return $captured;
    }

    /*
    |--------------------------------------------------------------------------
    | Tenancy & identity
    |--------------------------------------------------------------------------
    */

    public function test_two_organizations_may_share_the_number_1001_yet_keep_separate_lines(): void
    {
        $other = $this->organization();

        $deviceA = $this->createDevice();
        $deviceB = $this->createDevice(null, $other);

        $lineA = $deviceA->lines()->first();
        $lineB = $deviceB->lines()->first();

        // Both tenants legitimately use 1001 and both inbound paths restart at
        // 5000 — uniqueness is *per organization*, never global.
        $this->assertSame('1001', Extension::where('organization_id', $this->organization->id)->value('extension'));
        $this->assertSame('1001', Extension::where('organization_id', $other->id)->value('extension'));
        $this->assertSame('5000', $lineA->inbound_number);
        $this->assertSame('5000', $lineB->inbound_number);

        $this->assertSame($this->organization->id, $lineA->organization_id);
        $this->assertSame($other->id, $lineB->organization_id);

        // ... but the SIP identities are globally unique.
        $this->assertNotSame($lineA->sip_username, $lineB->sip_username);

        $this->assertSame('vodnox_gw_'.$this->organization->id.'_'.$lineA->id, $lineA->inboundContext());
        $this->assertSame('vodnox_gw_'.$other->id.'_'.$lineB->id, $lineB->inboundContext());
        $this->assertNotSame($lineA->inboundContext(), $lineB->inboundContext());
    }

    public function test_the_line_identity_is_globally_unique_and_never_the_employee_number(): void
    {
        $line = $this->createDevice()->lines()->first();

        $this->assertStringStartsWith('vdg'.$this->organization->id.'_', $line->sip_username);
        $this->assertNotSame('1001', $line->sip_username);
        $this->assertNotSame($line->inbound_number, $line->sip_username);
    }

    public function test_the_line_uses_the_classic_sip_profile_not_the_https_endpoints(): void
    {
        $line = $this->createDevice()->lines()->first();

        $this->assertSame('sip.gw.test', $line->sip_host);
        $this->assertSame(5060, $line->sip_port);
        $this->assertSame('udp', $line->sip_transport);
        $this->assertSame('gw.test', $line->sip_domain);
    }

    /*
    |--------------------------------------------------------------------------
    | Secrets
    |--------------------------------------------------------------------------
    */

    public function test_the_sip_password_is_encrypted_at_rest_and_hidden_from_json(): void
    {
        $line = $this->createDevice()->lines()->first();

        $plain = $line->sip_password;

        $this->assertNotSame($plain, DB::table('gateway_lines')->where('id', $line->id)->value('sip_password'));
        $this->assertSame($plain, $line->fresh()->sip_password);
        $this->assertArrayNotHasKey('sip_password', $line->toArray());
    }

    public function test_the_job_payload_never_carries_the_secret(): void
    {
        $line = $this->createDevice()->lines()->first();

        Queue::assertPushed(ProvisionTelephonyJob::class, function (ProvisionTelephonyJob $job) use ($line) {
            return $job->type === 'provision_gateway_line'
                && $job->gatewayLineId === $line->id
                && $job->organizationId === $this->organization->id
                && $job->payload['context'] === $line->inboundContext()
                && $job->payload['inbound_number'] === $line->inbound_number
                && ! array_key_exists('sip_password', $job->payload);
        });
    }

    public function test_the_run_time_secret_is_injected_but_stripped_from_the_stored_result(): void
    {
        $this->agent->echo = true;

        $line = $this->createDevice()->lines()->first();
        $job = $this->lastGatewayJob();

        $job->handle($this->agent);

        // The gateway really received the live password at run time...
        $call = collect($this->agent->calls)->last(fn (array $c) => $c[0] === 'provisionGatewayLine');
        $this->assertSame($line->sip_password, $call[1]['sip_password']);

        // ... but it is never written to provisioning_jobs.
        $record = DB::table('provisioning_jobs')->where('idempotency_key', $job->idempotencyKey)->first();
        $this->assertNotNull($record);
        $this->assertStringNotContainsString($line->sip_password, (string) $record->payload);
    }

    /*
    |--------------------------------------------------------------------------
    | Destination validation
    |--------------------------------------------------------------------------
    */

    public function test_a_line_is_not_pushed_when_its_destination_belongs_to_another_organization(): void
    {
        // The destination lives in tenant A, but the gateway line belongs to
        // tenant B: the pairing must be refused, not silently accepted.
        $organizationA = $this->organization();
        $organizationB = $this->organization();

        $foreignExtension = $this->destination($organizationA, '1001');

        $deviceB = $this->createDevice([[
            'label' => 'SIM X',
            'inbound_extension_id' => $foreignExtension->id,
            'enabled' => true,
        ]], $organizationB);

        $line = $deviceB->lines()->first();

        $this->assertSame($organizationB->id, $line->organization_id);
        $this->assertSame('failed', $line->provisioning_status);
        $this->assertSame('inbound_destination_unavailable', $line->provisioning_error);
        $this->assertSame('failed', $deviceB->fresh()->provisioning_status);

        // Nothing was pushed for the blocked line (a working line is never
        // silently switched off).
        Queue::assertNotPushed(
            ProvisionTelephonyJob::class,
            fn (ProvisionTelephonyJob $job) => $job->gatewayLineId === $line->id,
        );
    }

    public function test_destination_is_usable_only_for_an_active_extension_of_the_same_organization(): void
    {
        $mine = $this->destination();
        $device = $this->createDevice([[
            'label' => 'SIM 1',
            'inbound_extension_id' => $mine->id,
            'enabled' => true,
        ]]);
        $line = $device->lines()->first();

        $this->assertTrue($this->gateways->destinationIsUsable($line->fresh()));

        $mine->forceFill(['status' => 'inactive'])->save();
        $this->assertFalse($this->gateways->destinationIsUsable($line->fresh()));
    }

    /*
    |--------------------------------------------------------------------------
    | Desired state: enable / disable
    |--------------------------------------------------------------------------
    */

    public function test_disabling_a_line_keeps_its_identity_and_pushes_enabled_false(): void
    {
        $device = $this->createDevice();
        $line = $device->lines()->first();
        $username = $line->sip_username;

        $this->gateways->setLineEnabled($line, false);

        $line->refresh();

        $this->assertFalse($line->enabled);
        $this->assertSame($username, $line->sip_username);

        Queue::assertPushed(
            ProvisionTelephonyJob::class,
            fn (ProvisionTelephonyJob $job) => $job->gatewayLineId === $line->id
                && $job->payload['enabled'] === false,
        );
    }

    public function test_re_enabling_a_line_re_provisions_for_real_with_a_new_revision(): void
    {
        $device = $this->createDevice();
        $line = $device->lines()->first();

        $created = (int) $line->provisioning_revision;

        $this->gateways->setLineEnabled($line, false);
        $disabled = (int) $line->fresh()->provisioning_revision;
        $this->assertGreaterThan($created, $disabled);

        $this->gateways->setLineEnabled($line->fresh(), true);
        $reenabled = (int) $line->fresh()->provisioning_revision;

        $this->assertTrue($line->fresh()->enabled);
        $this->assertGreaterThan($disabled, $reenabled);

        Queue::assertPushed(
            ProvisionTelephonyJob::class,
            fn (ProvisionTelephonyJob $job) => $job->gatewayLineId === $line->id
                && $job->payload['enabled'] === true,
        );
    }

    public function test_deleting_a_device_removes_every_line_and_its_identity_from_the_server(): void
    {
        $device = $this->createDevice();
        $line = $device->lines()->first();
        $username = $line->sip_username;

        $this->gateways->deleteDevice($device);

        Queue::assertPushed(
            ProvisionTelephonyJob::class,
            fn (ProvisionTelephonyJob $job) => $job->type === 'deprovision_gateway_line'
                && $job->payload['sip_username'] === $username,
        );

        $this->assertDatabaseMissing('gateway_lines', ['id' => $line->id]);
        $this->assertDatabaseMissing('gateway_devices', ['id' => $device->id]);
    }

    public function test_rotation_changes_the_password_and_pushes_the_new_one(): void
    {
        $device = $this->createDevice();
        $line = $device->lines()->first();

        $old = $line->sip_password;

        $this->gateways->rotateLineSecret($line);

        $this->assertNotSame($old, $line->fresh()->sip_password);

        $job = $this->lastGatewayJob();
        $job->handle($this->agent);

        $call = collect($this->agent->calls)->last(fn (array $c) => $c[0] === 'provisionGatewayLine');
        $this->assertSame($line->fresh()->sip_password, $call[1]['sip_password']);
    }

    /*
    |--------------------------------------------------------------------------
    | Concurrency
    |--------------------------------------------------------------------------
    */

    public function test_the_lock_is_keyed_on_the_resource_not_on_the_revision(): void
    {
        $device = $this->createDevice();
        $line = $device->lines()->first();

        $first = new ProvisionTelephonyJob('provision_gateway_line', $this->organization->id, ['revision' => 1], 'k1', null, $device->id, $line->id);
        $second = new ProvisionTelephonyJob('provision_gateway_line', $this->organization->id, ['revision' => 99], 'k2', null, $device->id, $line->id);

        $expected = 'gateway-line:'.$this->organization->id.':'.$device->id.':'.$line->id;

        $this->assertSame($expected, $first->resourceKey());
        $this->assertSame($expected, $second->resourceKey());
        $this->assertStringNotContainsString('revision', $first->resourceKey());
    }

    public function test_a_late_retry_cannot_overwrite_a_newer_revision(): void
    {
        $device = $this->createDevice();
        $line = $device->lines()->first();

        $current = $this->lastGatewayJob();

        // A stale retry carries an older revision and must be ignored.
        $stale = new ProvisionTelephonyJob(
            'provision_gateway_line',
            $this->organization->id,
            ['revision' => 1, 'enabled' => true],
            'stale-key',
            null,
            $device->id,
            $line->id,
        );

        $stale->handle($this->agent);
        $this->assertSame('pending', $line->fresh()->provisioning_status);

        // The current revision still wins and marks the line synced.
        $current->handle($this->agent);
        $this->assertSame('synced', $line->fresh()->provisioning_status);
        $this->assertSame('synced', $device->fresh()->provisioning_status);
    }

    public function test_a_reload_failure_is_recorded_and_can_be_retried(): void
    {
        $device = $this->createDevice();
        $line = $device->lines()->first();
        $job = $this->lastGatewayJob();

        $this->agent->fail = ['provisionGatewayLine'];

        try {
            $job->handle($this->agent);
            $this->fail('Expected the provisioning job to surface the gateway failure.');
        } catch (\RuntimeException) {
            // expected: the gateway refused the reload
        }

        $this->assertSame('failed', $line->fresh()->provisioning_status);
        $this->assertSame('failed', $device->fresh()->provisioning_status);

        // The manager retries after the server is healthy again.
        $this->agent->fail = [];
        $this->gateways->syncDevice($device->fresh());

        $this->assertSame('pending', $line->fresh()->provisioning_status);
        $this->assertNull($line->fresh()->provisioning_error);
    }

    /*
    |--------------------------------------------------------------------------
    | Capability negotiation & monitoring honesty
    |--------------------------------------------------------------------------
    */

    public function test_an_old_agent_asks_for_a_service_update_instead_of_faking_a_sync(): void
    {
        $this->agent->capabilitiesResponse = [
            'agent_version' => '1.0.0',
            'capabilities' => ['organizations', 'extensions'],
        ];

        $this->assertFalse($this->gateways->agentSupportsGateways(true));

        $device = $this->createDevice();
        $result = $this->gateways->refreshStatus($device);

        $this->assertFalse($result['ok']);
        $this->assertSame('agent_update_required', $result['message']);

        $line = $device->lines()->first();
        $this->assertSame('unknown', $line->registration_state);
        $this->assertSame('unknown', $line->registrationLabel());
        $this->assertSame('unknown', $device->fresh()->registration_state);
    }

    public function test_an_unavailable_monitoring_source_is_reported_as_unknown_not_disconnected(): void
    {
        $this->agent->statusResponse = ['source' => 'unavailable', 'lines' => []];

        $device = $this->createDevice();
        $result = $this->gateways->refreshStatus($device);

        $this->assertFalse($result['ok']);
        $this->assertSame('monitoring_unavailable', $result['message']);

        $line = $device->lines()->first();
        $this->assertSame('unknown', $line->registration_state);
        $this->assertNull($line->registration_observed_at);
        $this->assertSame('unknown', $device->fresh()->registrationLabel());
    }

    public function test_a_real_registration_snapshot_marks_the_device_registered(): void
    {
        $device = $this->createDevice();
        $line = $device->lines()->first();

        $this->agent->statusResponse = [
            'source' => 'pjsip',
            'lines' => [
                $line->sip_username => ['state' => 'registered', 'registered' => true],
            ],
        ];

        $result = $this->gateways->refreshStatus($device);

        $this->assertTrue($result['ok']);
        $this->assertSame('registered', $line->fresh()->registration_state);
        $this->assertSame('registered', $device->fresh()->registration_state);
        $this->assertSame('registered', $device->fresh()->registrationLabel());
        $this->assertFalse($device->fresh()->registrationIsStale());
    }

    public function test_a_stale_registration_reading_is_reported_as_unknown(): void
    {
        $device = $this->createDevice();

        $device->forceFill([
            'registration_state' => 'registered',
            'registration_observed_at' => now()->subMinutes(30),
        ])->save();

        $this->assertTrue($device->registrationIsStale());
        $this->assertSame('unknown', $device->registrationLabel());
    }

    /*
    |--------------------------------------------------------------------------
    | Event mapping
    |--------------------------------------------------------------------------
    */

    public function test_an_inbound_mobile_call_is_attributed_to_the_gateway_owner_not_the_caller_id(): void
    {
        $calls = app(CallEventService::class);

        $device = $this->createDevice();
        $line = $device->lines()->first();
        $destination = Extension::where('organization_id', $this->organization->id)->firstOrFail();

        $otherOrganization = $this->organization();

        $calls->apply([
            'event_type' => 'ringing',
            'caller_username' => $line->sip_username,
            'callee_username' => $destination->sip_username,
            'linkedid' => 'gw-linked-1',
            'occurred_at' => now()->toIso8601String(),
            // A spoofed tenant id and Caller-ID must be ignored.
            'organization_id' => $otherOrganization->id,
            'caller_id' => '999999',
        ]);

        $call = Call::query()->sole();

        $this->assertSame($this->organization->id, $call->organization_id);
        $this->assertSame('inbound', $call->direction);
    }
}
