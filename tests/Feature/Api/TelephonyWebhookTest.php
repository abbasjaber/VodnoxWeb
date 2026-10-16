<?php

namespace Tests\Feature\Api;

use App\Models\Extension;
use App\Models\Organization;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\BuildsTenant;
use Tests\TestCase;

/**
 * Telephony gateway webhook contract: HMAC authenticity + idempotency.
 *
 * The endpoint is public but must reject everything that is not a correctly
 * signed request, and a repeated event_id must never create a second row.
 */
class TelephonyWebhookTest extends TestCase
{
    use BuildsTenant;

    private const URL = '/api/v1/telephony/webhook';

    private const SECRET = 'test-webhook-secret';

    private function configure(?string $secret = self::SECRET): void
    {
        Config::set('integrations.telephony.webhook_secret', $secret);
    }

    private function send(array $payload, ?string $signature, ?string $rawBody = null)
    {
        $body = $rawBody ?? json_encode($payload);

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        if ($signature !== null) {
            $server['HTTP_X_VODNOX_SIGNATURE'] = $signature;
        }

        return $this->call('POST', self::URL, [], [], [], $server, $body);
    }

    private function sign(string $body, string $secret = self::SECRET): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    public function test_returns_503_when_the_gateway_is_not_configured(): void
    {
        $this->configure(null);

        $payload = ['event_id' => 'evt-1', 'event_type' => 'call.started'];

        $this->send($payload, $this->sign(json_encode($payload)))
            ->assertStatus(503)
            ->assertJson(['success' => false]);
    }

    public function test_rejects_a_request_without_a_signature(): void
    {
        $this->configure();

        $this->send(['event_id' => 'evt-1', 'event_type' => 'call.started'], null)
            ->assertStatus(401);
    }

    public function test_rejects_a_request_with_an_invalid_signature(): void
    {
        $this->configure();

        $this->send(['event_id' => 'evt-1', 'event_type' => 'call.started'], 'deadbeef')
            ->assertStatus(401);

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_accepts_a_valid_signature_and_records_the_event(): void
    {
        $this->configure();

        $payload = ['event_id' => 'evt-100', 'event_type' => 'call.ended', 'occurred_at' => now()->toIso8601String()];
        $body = json_encode($payload);

        $this->send($payload, $this->sign($body))
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.duplicate', false);

        $this->assertDatabaseHas('webhook_events', [
            'event_id' => 'evt-100',
            'event_type' => 'call.ended',
            'status' => 'processed',
        ]);
    }

    public function test_a_duplicate_event_id_is_ignored(): void
    {
        $this->configure();

        $payload = ['event_id' => 'evt-dup', 'event_type' => 'call.ended'];
        $body = json_encode($payload);
        $signature = $this->sign($body);

        $this->send($payload, $signature)->assertOk()->assertJsonPath('data.duplicate', false);
        $this->send($payload, $signature)->assertOk()->assertJsonPath('data.duplicate', true);

        $this->assertSame(1, WebhookEvent::where('event_id', 'evt-dup')->count());
    }

    public function test_missing_event_id_is_rejected_as_invalid_payload(): void
    {
        $this->configure();

        $payload = ['event_type' => 'call.started'];
        $body = json_encode($payload);

        $this->send($payload, $this->sign($body))->assertStatus(422);

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_an_unknown_organization_id_is_stored_as_null(): void
    {
        $this->configure();

        $payload = ['event_id' => 'evt-org', 'event_type' => 'call.ended', 'organization_id' => 999999];
        $body = json_encode($payload);

        $this->send($payload, $this->sign($body))->assertOk();

        $this->assertDatabaseHas('webhook_events', [
            'event_id' => 'evt-org',
            'organization_id' => null,
        ]);
    }

    public function test_a_known_organization_id_is_persisted(): void
    {
        $this->configure();

        $organization = $this->organization();

        $payload = ['event_id' => 'evt-known', 'event_type' => 'call.ended', 'organization_id' => $organization->id];
        $body = json_encode($payload);

        $this->send($payload, $this->sign($body))->assertOk();

        $this->assertDatabaseHas('webhook_events', [
            'event_id' => 'evt-known',
            'organization_id' => $organization->id,
        ]);
        $this->assertInstanceOf(Organization::class, $organization);
    }

    public function test_a_signed_event_for_a_known_extension_reaches_the_call_log(): void
    {
        $this->configure();

        $organization = $this->organization();
        $agent = $this->orgUser($organization, 'agent', ['extension' => '1001']);

        $extension = Extension::create([
            'organization_id' => $organization->id,
            'user_id' => $agent->id,
            'extension' => '1001',
            'status' => 'active',
            'provisioning_status' => 'synced',
            'sip_username' => 'vdx'.$organization->id.'_webhookagent',
            'sip_password' => 'sip-secret-webhook',
        ]);

        $payload = [
            'event_id' => 'evt-live-1',
            'event_type' => 'ringing',
            'caller_username' => $extension->sip_username,
            'callee_username' => $extension->sip_username,
            'linkedid' => 'linked-live-1',
            'occurred_at' => now()->toIso8601String(),
        ];
        $body = json_encode($payload);

        $this->send($payload, $this->sign($body))->assertOk();

        // The organization is derived from the endpoint, not from the payload.
        $this->assertDatabaseHas('calls', [
            'organization_id' => $organization->id,
            'external_call_id' => 'linked-live-1',
            'status' => 'ringing',
        ]);

        // A retry of the same event must not touch the call log twice.
        $this->send($payload, $this->sign($body))->assertOk()->assertJsonPath('data.duplicate', true);
        $this->assertDatabaseCount('calls', 1);
    }
}
