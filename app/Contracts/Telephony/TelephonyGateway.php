<?php

namespace App\Contracts\Telephony;

/**
 * Contract for the external telephony server (Asterisk/FreePBX/other).
 *
 * Two implementations exist:
 *  - {@see \App\Telephony\HttpTelephonyGateway} talks to the VODNOX
 *    provisioning agent over HTTPS (see deploy/telephony).
 *  - {@see \App\Telephony\SimulatedTelephonyGateway} is used when no server is
 *    configured and performs no network calls whatsoever.
 *
 * "Provision" is an idempotent reconcile (create-or-update) keyed by the
 * identifiers in the payload; the `enabled` flag in the payload is what
 * disables/enables the account on the server without losing its identity.
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
     * Remove an extension/SIP account from the telephony server.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function deprovisionExtension(array $payload): array;

    /**
     * Remove an organization (its context and every account under it).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function deprovisionOrganization(array $payload): array;

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
