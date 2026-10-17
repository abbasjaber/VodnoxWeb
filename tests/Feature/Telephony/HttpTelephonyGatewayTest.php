<?php

namespace Tests\Feature\Telephony;

use App\Telephony\HttpTelephonyGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The HTTP adapter for the provisioning agent must sign every route it calls —
 * including the read-only health probe — because the agent authenticates the
 * request before it looks at the path.
 *
 * Verified here:
 *   - GET /health carries a Bearer token, a fresh timestamp/nonce pair and the
 *     HMAC over "timestamp.nonce." + empty body;
 *   - a rejected probe is reported as an error instead of an exception;
 *   - the signing secret itself never travels in a header or a body.
 */
class HttpTelephonyGatewayTest extends TestCase
{
    private const SECRET = 'test-signing-secret';

    private function gateway(): HttpTelephonyGateway
    {
        return new HttpTelephonyGateway([
            'base_url' => 'https://agent.test',
            'token' => 'test-bearer-token',
            'signing_secret' => self::SECRET,
        ]);
    }

    public function test_the_health_probe_is_signed_over_an_empty_body(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $result = $this->gateway()->testConnection();

        $this->assertTrue($result['ok']);
        $this->assertSame('connected', $result['status']);

        Http::assertSent(function (Request $request) {
            $timestamp = $this->header($request, 'X-VODNOX-Timestamp');
            $nonce = $this->header($request, 'X-VODNOX-Nonce');
            $signature = $this->header($request, 'X-VODNOX-Signature');

            return $request->method() === 'GET'
                && $request->url() === 'https://agent.test/health'
                && $this->header($request, 'Authorization') === 'Bearer test-bearer-token'
                && $timestamp !== ''
                && $nonce !== ''
                && $signature === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$nonce.'.', self::SECRET);
        });
    }

    public function test_every_probe_uses_a_fresh_nonce(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $gateway = $this->gateway();
        $gateway->testConnection();
        $gateway->testConnection();

        $nonces = [];
        Http::assertSent(function (Request $request) use (&$nonces) {
            $nonces[] = $this->header($request, 'X-VODNOX-Nonce');

            return true;
        });

        $this->assertCount(2, $nonces);
        $this->assertNotSame($nonces[0], $nonces[1]);
    }

    public function test_a_rejected_probe_is_reported_as_an_error_not_a_throw(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

        $result = $this->gateway()->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertSame('error', $result['status']);
        $this->assertSame('HTTP 401', $result['message']);
    }

    public function test_the_signing_secret_never_leaves_the_server(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->gateway()->testConnection();

        Http::assertSent(function (Request $request) {
            $dump = json_encode($request->headers()).'|'.$request->body();

            return ! str_contains($dump, self::SECRET)
                && ! str_contains($request->body(), self::SECRET);
        });
    }

    private function header(Request $request, string $name): string
    {
        return $request->header($name)[0] ?? '';
    }
}
