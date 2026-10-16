<?php

namespace App\Contracts\Telephony;

/**
 * Contract for the external telephony server (Asterisk/FreePBX/other).
 *
 * This is a documented contract only. No real connection is performed until
 * the institution supplies server credentials; until then the simulated
 * implementation is bound in the container.
 */
interface TelephonyGateway
{
    /**
     * Provision (or reconcile) an organization on the telephony server.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function provisionOrganization(array $payload): array;

    /**
     * Provision (or reconcile) a single extension/SIP account.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function provisionExtension(array $payload): array;

    /**
     * Provision (or reconcile) an IVR menu with its options.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function provisionIvr(array $payload): array;

    /**
     * Provision (or reconcile) a call queue with its members.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function provisionQueue(array $payload): array;

    /**
     * Probe connectivity/auth. Never throws for an expected outage; returns a status array.
     *
     * @return array{ok: bool, status: string, message?: string}
     */
    public function testConnection(): array;

    /**
     * Whether this gateway performs real network calls.
     */
    public function isSimulated(): bool;
}
