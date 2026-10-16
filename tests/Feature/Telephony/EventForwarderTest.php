<?php

namespace Tests\Feature\Telephony;

use App\Services\Telephony\TelephonyWebhookService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;
use Vodnox\Telephony\Events\AmiClient;
use Vodnox\Telephony\Events\CallTracker;
use Vodnox\Telephony\Events\ChannelIdentity;
use Vodnox\Telephony\Events\EventPublisher;
use Vodnox\Telephony\Events\Logger;
use Vodnox\Telephony\Events\Runner;
use Vodnox\Telephony\Events\Spool;
use Vodnox\Telephony\Events\WebhookSigner;
use Vodnox\Telephony\Events\WebhookTransport;

require_once dirname(__DIR__, 3).'/deploy/telephony/events/bootstrap.php';

/**
 * The call-event forwarder that runs on the media server.
 *
 * These tests exist because the repository previously had only a receiver: a
 * passing webhook test proved nothing about who actually sends events. What is
 * asserted here is the whole path — an AMI frame becomes a VODNOX event, the
 * event is written to disk before it is sent, the signature matches what the
 * Laravel controller verifies, and a failure is retried without losing order.
 */
class EventForwarderTest extends TestCase
{
    private const SECRET = 'forwarder-test-secret';

    private string $spoolDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spoolDir = sys_get_temp_dir().'/vodnox-spool-'.bin2hex(random_bytes(6));
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Channel identity
    // -----------------------------------------------------------------

    public function test_only_managed_endpoints_are_recognised(): void
    {
        $this->assertTrue(ChannelIdentity::isManaged('PJSIP/vdx12_ab12cd34ef56-0000002a'));
        $this->assertSame('vdx12_ab12cd34ef56', ChannelIdentity::parse('PJSIP/vdx12_ab12cd34ef56-0000002a')['username']);
        $this->assertSame(12, ChannelIdentity::organizationId('PJSIP/vdx12_ab12cd34ef56-0000002a'));
    }

    public function test_legacy_and_foreign_channels_are_never_tracked(): void
    {
        // The old test endpoints, a trunk, a local channel and the old context.
        $this->assertFalse(ChannelIdentity::isManaged('PJSIP/1001-00000001'));
        $this->assertFalse(ChannelIdentity::isManaged('PJSIP/1002-00000001'));
        $this->assertFalse(ChannelIdentity::isManaged('SIP/1001-00000001'));
        $this->assertFalse(ChannelIdentity::isManaged('Local/1001@vodnox-test-00000001;1'));
        $this->assertFalse(ChannelIdentity::isManaged('PJSIP/vdxcarrier-00000001'));

        // A legacy endpoint has no organization, whatever its channel name is.
        $this->assertNull(ChannelIdentity::organizationId('PJSIP/1001-00000001'));
        $this->assertNull(ChannelIdentity::parse('SIP/1001-00000001'));
    }

    // -----------------------------------------------------------------
    // CallTracker
    // -----------------------------------------------------------------

    public function test_a_bridged_call_becomes_ringing_answered_and_ended(): void
    {
        $tracker = new CallTracker();
        $origin = 'PJSIP/vdx12_aaaaaaaaaaaa-00000001';
        $peer = 'PJSIP/vdx12_bbbbbbbbbbbb-00000002';
        $linkedid = '1729000000.10';

        // Asterisk gives the originating leg a Uniqueid equal to its Linkedid.
        $this->assertSame([], $tracker->ingest($this->channel($origin, $linkedid, $linkedid, 4, '1002'), 1000.0));
        $this->assertSame([], $tracker->ingest($this->channel($peer, $linkedid, '1729000000.11', 4, '1002'), 1000.1));

        $ringing = $tracker->ingest($this->state($peer, $linkedid, 5), 1001.0);
        $this->assertSame('ringing', $ringing[0]['event_type']);
        $this->assertSame('vdx12_aaaaaaaaaaaa', $ringing[0]['caller_username']);
        $this->assertSame('vdx12_bbbbbbbbbbbb', $ringing[0]['callee_username']);
        $this->assertSame($linkedid, $ringing[0]['linkedid']);
        $this->assertSame('internal', $ringing[0]['direction']);

        $answered = $tracker->ingest($this->state($peer, $linkedid, 6), 1002.0);
        $this->assertSame('answered', $answered[0]['event_type']);

        // The first leg hangs up: the call must NOT be closed yet.
        $this->assertSame([], $tracker->ingest($this->hangup($peer, $linkedid, 16), 1030.0));

        $ended = $tracker->ingest($this->hangup($origin, $linkedid, 16), 1040.0);
        $this->assertSame('ended', $ended[0]['event_type']);
        $this->assertSame(38, $ended[0]['duration_seconds']);   // measured from the answer
        $this->assertSame('normal_clearing', $ended[0]['hangup_cause']);
    }

    public function test_a_refused_call_is_failed_with_its_cause(): void
    {
        $tracker = new CallTracker();
        $origin = 'PJSIP/vdx12_aaaaaaaaaaaa-00000003';
        $linkedid = '1729000000.20';

        $tracker->ingest($this->channel($origin, $linkedid, $linkedid, 4, '1002'), 5000.0);
        $tracker->ingest($this->state($origin, $linkedid, 5), 5000.1);

        $events = $tracker->ingest($this->hangup($origin, $linkedid, 21), 5001.0);

        $this->assertSame('failed', $events[0]['event_type']);
        $this->assertSame('call_rejected', $events[0]['hangup_cause']);
        $this->assertSame(0, $events[0]['duration_seconds']);
    }

    public function test_a_busy_destination_is_reported_as_busy(): void
    {
        $tracker = new CallTracker();
        $origin = 'PJSIP/vdx12_aaaaaaaaaaaa-00000004';
        $peer = 'PJSIP/vdx12_bbbbbbbbbbbb-00000005';
        $linkedid = '1729000000.30';

        $tracker->ingest($this->channel($origin, $linkedid, $linkedid, 4, '1002'), 7000.0);
        $tracker->ingest($this->channel($peer, $linkedid, '1729000000.31', 4, '1002'), 7000.1);

        $events = $tracker->ingest($this->state($peer, $linkedid, 7), 7001.0);

        $this->assertSame('busy', $events[0]['event_type']);
        $this->assertSame('user_busy', $events[0]['hangup_cause']);

        // The call is closed; a later hangup for the other leg is ignored.
        $this->assertSame([], $tracker->ingest($this->hangup($origin, $linkedid, 16), 7002.0));
    }

    public function test_events_from_another_context_or_endpoint_are_ignored(): void
    {
        $tracker = new CallTracker();
        $linkedid = '1729000000.40';

        // A managed-looking endpoint in the legacy shared context.
        $this->assertSame([], $tracker->ingest([
            'Event' => 'Newchannel',
            'Channel' => 'PJSIP/vdx12_cccccccccccc-00000006',
            'Uniqueid' => $linkedid,
            'Linkedid' => $linkedid,
            'Context' => 'vodnox-test',
            'ChannelState' => '4',
        ], 100.0));

        // The old endpoints, even in a valid context.
        $this->assertSame([], $tracker->ingest([
            'Event' => 'Newchannel',
            'Channel' => 'PJSIP/1001-00000001',
            'Uniqueid' => $linkedid,
            'Linkedid' => $linkedid,
            'Context' => 'vodnox_org_12',
            'ChannelState' => '4',
        ], 100.5));

        // Nothing was tracked, so a hangup cannot invent a call.
        $this->assertSame([], $tracker->ingest([
            'Event' => 'Hangup',
            'Channel' => 'PJSIP/1001-00000001',
            'Linkedid' => $linkedid,
            'Cause' => '16',
        ], 101.0));
    }

    public function test_event_ids_are_stable_so_a_redelivery_is_idempotent(): void
    {
        $this->assertSame(
            CallTracker::eventId('1729000000.50', 'answered'),
            CallTracker::eventId('1729000000.50', 'answered'),
        );

        $this->assertNotSame(
            CallTracker::eventId('1729000000.50', 'answered'),
            CallTracker::eventId('1729000000.50', 'ended'),
        );
    }

    // -----------------------------------------------------------------
    // Spool
    // -----------------------------------------------------------------

    public function test_the_spool_keeps_order_and_refuses_duplicates(): void
    {
        $spool = new Spool($this->spoolDir);

        $this->assertTrue($spool->enqueue(['event_id' => 'evt-first', 'event_type' => 'ringing']));
        $this->assertTrue($spool->enqueue(['event_id' => 'evt-second', 'event_type' => 'answered']));

        // The same event id is queued once only.
        $this->assertFalse($spool->enqueue(['event_id' => 'evt-first', 'event_type' => 'ringing']));
        $this->assertSame(2, $spool->size());

        $ordered = array_map(
            static fn (array $entry): string => (string) $entry['payload']['event_id'],
            $spool->pending(),
        );

        $this->assertSame(['evt-first', 'evt-second'], $ordered);
    }

    public function test_a_corrupt_entry_is_quarantined_instead_of_blocking_the_queue(): void
    {
        $spool = new Spool($this->spoolDir);
        $spool->enqueue(['event_id' => 'evt-good', 'event_type' => 'ringing']);

        file_put_contents($this->spoolDir.'/0000000000000000000001-evt-broken.json', '{not json');

        $pending = $spool->pending();

        $this->assertCount(1, $pending);
        $this->assertSame('evt-good', $pending[0]['payload']['event_id']);
        $this->assertFileExists($this->spoolDir.'/rejected/0000000000000000000001-evt-broken.json');
    }

    // -----------------------------------------------------------------
    // Signing
    // -----------------------------------------------------------------

    public function test_the_signature_is_exactly_what_the_application_verifies(): void
    {
        Config::set('integrations.telephony.webhook_secret', self::SECRET);

        $body = json_encode(['event_id' => 'evt-1', 'event_type' => 'ringing', 'linkedid' => 'x']);
        $signer = new WebhookSigner(self::SECRET);

        $headers = $signer->headers($body);
        $signature = null;

        foreach ($headers as $header) {
            if (str_starts_with($header, WebhookSigner::HEADER.': ')) {
                $signature = substr($header, strlen(WebhookSigner::HEADER) + 2);
            }
        }

        $this->assertNotNull($signature);

        // Verified through the real receiver, not a re-implementation of it.
        $this->assertTrue(app(TelephonyWebhookService::class)->verifySignature($body, $signature));

        // Any tampering with the body invalidates it.
        $this->assertFalse(app(TelephonyWebhookService::class)->verifySignature($body.' ', $signature));
    }

    // -----------------------------------------------------------------
    // Delivery policy
    // -----------------------------------------------------------------

    public function test_an_accepted_event_is_removed_from_the_spool(): void
    {
        $spool = new Spool($this->spoolDir);
        $spool->enqueue(['event_id' => 'evt-a', 'event_type' => 'ringing']);

        $transport = new RecordingTransport();
        $publisher = $this->publisher($spool, $transport);

        $this->assertSame(1, $publisher->deliverPending());
        $this->assertSame(0, $spool->size());
        $this->assertCount(1, $transport->requests);
    }

    public function test_a_transport_failure_keeps_every_event_and_its_order(): void
    {
        $spool = new Spool($this->spoolDir);
        $spool->enqueue(['event_id' => 'evt-a', 'event_type' => 'ringing']);
        $spool->enqueue(['event_id' => 'evt-b', 'event_type' => 'answered']);

        // null means the request never reached the application (DNS/TLS/timeout).
        $transport = new RecordingTransport([null]);
        $publisher = $this->publisher($spool, $transport);

        $this->assertSame(0, $publisher->deliverPending());

        // The batch stopped at the first failure: no later event overtook it.
        $this->assertCount(1, $transport->requests);
        $this->assertSame(2, $spool->size());

        // Once the application is reachable again, both go out in order.
        $transport->requests = [];
        $transport->responses = [200, 200];
        $this->assertSame(2, $publisher->deliverPending());

        $this->assertSame(
            ['evt-a', 'evt-b'],
            array_map(
                static fn (array $request): string => (string) json_decode($request['body'], true)['event_id'],
                $transport->requests,
            ),
        );
        $this->assertSame(0, $spool->size());
    }

    public function test_a_permanent_rejection_is_quarantined_and_the_rest_continue(): void
    {
        $spool = new Spool($this->spoolDir);
        $spool->enqueue(['event_id' => 'evt-refused', 'event_type' => 'ringing']);
        $spool->enqueue(['event_id' => 'evt-ok', 'event_type' => 'answered']);

        // 401: the signature was refused, which retrying can never fix.
        $transport = new RecordingTransport([401, 200]);
        $publisher = $this->publisher($spool, $transport);

        $this->assertSame(1, $publisher->deliverPending());
        $this->assertSame(0, $spool->size());
        $this->assertFileExists(glob($this->spoolDir.'/rejected/*-evt-refused.json')[0] ?? '');
    }

    public function test_a_server_error_is_retried_not_quarantined(): void
    {
        $spool = new Spool($this->spoolDir);
        $spool->enqueue(['event_id' => 'evt-a', 'event_type' => 'ringing']);

        $publisher = $this->publisher($spool, new RecordingTransport([503]));

        $this->assertSame(0, $publisher->deliverPending());
        $this->assertSame(1, $spool->size());
        $this->assertSame([], glob($this->spoolDir.'/rejected/*'));
    }

    // -----------------------------------------------------------------
    // End to end over a socket pair (no Asterisk involved)
    // -----------------------------------------------------------------

    public function test_a_real_ami_stream_becomes_signed_events_for_the_application(): void
    {
        Config::set('integrations.telephony.webhook_secret', self::SECRET);

        $spool = new Spool($this->spoolDir);
        $transport = new RecordingTransport();
        $publisher = $this->publisher($spool, $transport);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        [$client, $server] = $pair;

        $frames = [
            ['Event' => 'Newchannel', 'Channel' => 'PJSIP/vdx12_aaaaaaaaaaaa-00000001', 'Uniqueid' => '1729000000.90', 'Linkedid' => '1729000000.90', 'Context' => 'vodnox_org_12', 'ChannelState' => '4', 'CallerIDNum' => '1001', 'Exten' => '1002'],
            ['Event' => 'Newchannel', 'Channel' => 'PJSIP/vdx12_bbbbbbbbbbbb-00000002', 'Uniqueid' => '1729000000.91', 'Linkedid' => '1729000000.90', 'Context' => 'vodnox_org_12', 'ChannelState' => '4'],
            ['Event' => 'Newstate', 'Channel' => 'PJSIP/vdx12_bbbbbbbbbbbb-00000002', 'Linkedid' => '1729000000.90', 'ChannelState' => '5', 'ChannelStateDesc' => 'Ringing'],
            ['Event' => 'Newstate', 'Channel' => 'PJSIP/vdx12_bbbbbbbbbbbb-00000002', 'Linkedid' => '1729000000.90', 'ChannelState' => '6', 'ChannelStateDesc' => 'Up'],
            // A legacy endpoint in the same batch must produce nothing at all.
            ['Event' => 'Newchannel', 'Channel' => 'PJSIP/1001-00000009', 'Uniqueid' => '1729000000.99', 'Linkedid' => '1729000000.99', 'Context' => 'vodnox-test', 'ChannelState' => '4'],
            ['Event' => 'Hangup', 'Channel' => 'PJSIP/vdx12_bbbbbbbbbbbb-00000002', 'Linkedid' => '1729000000.90', 'Cause' => '16'],
            ['Event' => 'Hangup', 'Channel' => 'PJSIP/vdx12_aaaaaaaaaaaa-00000001', 'Linkedid' => '1729000000.90', 'Cause' => '16'],
        ];

        $payload = "Response: Success\r\nMessage: Authentication accepted\r\n\r\n";

        foreach ($frames as $frame) {
            foreach ($frame as $key => $value) {
                $payload .= $key.': '.$value."\r\n";
            }
            $payload .= "\r\n";
        }

        fwrite($server, $payload);
        fclose($server);

        $runner = new Runner(new Logger(fopen('php://memory', 'w')), new CallTracker(), $publisher);
        $processed = $runner->consume(new AmiClient($client, 'vodnox-events', 'secret'));

        $this->assertSame(count($frames), $processed);

        $delivered = array_map(
            static fn (array $request): array => json_decode($request['body'], true),
            $transport->requests,
        );

        $this->assertSame(
            ['ringing', 'answered', 'ended'],
            array_map(static fn (array $event): string => $event['event_type'], $delivered),
        );

        // The legacy call never entered the spool, and every event is signed.
        $this->assertSame(0, $spool->size());

        foreach ($delivered as $index => $event) {
            $signature = null;

            foreach ($transport->requests[$index]['headers'] as $header) {
                if (str_starts_with($header, WebhookSigner::HEADER.': ')) {
                    $signature = substr($header, strlen(WebhookSigner::HEADER) + 2);
                }
            }

            $this->assertTrue(
                app(TelephonyWebhookService::class)->verifySignature($transport->requests[$index]['body'], $signature),
                'event '.$event['event_type'].' must be signed with the shared secret',
            );
            $this->assertSame('1729000000.90', $event['linkedid']);
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @param  array<int, int|null>  $responses
     */
    private function publisher(Spool $spool, RecordingTransport $transport): EventPublisher
    {
        return new EventPublisher(
            $spool,
            $transport,
            new WebhookSigner(self::SECRET),
            'https://vodnox.com/api/v1/telephony/webhook',
            new Logger(fopen('php://memory', 'w')),
        );
    }

    /**
     * @return array<string, string>
     */
    private function channel(string $channel, string $linkedid, string $uniqueid, int $state, string $exten): array
    {
        return [
            'Event' => 'Newchannel',
            'Channel' => $channel,
            'Uniqueid' => $uniqueid,
            'Linkedid' => $linkedid,
            'Context' => 'vodnox_org_12',
            'ChannelState' => (string) $state,
            'CallerIDNum' => '1001',
            'Exten' => $exten,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function state(string $channel, string $linkedid, int $state): array
    {
        return [
            'Event' => 'Newstate',
            'Channel' => $channel,
            'Linkedid' => $linkedid,
            'ChannelState' => (string) $state,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function hangup(string $channel, string $linkedid, int $cause): array
    {
        return [
            'Event' => 'Hangup',
            'Channel' => $channel,
            'Linkedid' => $linkedid,
            'Cause' => (string) $cause,
        ];
    }

    private function cleanUp(): void
    {
        if (! is_dir($this->spoolDir)) {
            return;
        }

        foreach (['/*.json', '/rejected/*'] as $pattern) {
            foreach (glob($this->spoolDir.$pattern) ?: [] as $file) {
                @unlink($file);
            }
        }

        @rmdir($this->spoolDir.'/rejected');
        @rmdir($this->spoolDir);
    }
}

/**
 * A transport that records what would have been sent and replays scripted
 * responses, so the publisher's policy is exercised without any network.
 */
final class RecordingTransport implements WebhookTransport
{
    /** @var array<int, array{url: string, headers: array<int, string>, body: string}> */
    public array $requests = [];

    public int $calls = 0;

    /**
     * @param  array<int, int|null>  $responses
     */
    public function __construct(public array $responses = [])
    {
    }

    public function post(string $url, array $headers, string $body, float $timeout): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
        $status = array_key_exists($this->calls, $this->responses) ? $this->responses[$this->calls] : 200;
        $this->calls++;

        return ['status' => $status, 'body' => ''];
    }
}
