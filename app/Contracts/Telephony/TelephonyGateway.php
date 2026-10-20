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
     * Provision (or reconcile) one line of a GSM gateway.
     *
     * The payload carries the organization, the line identifiers, the inbound
     * context, the destination endpoint and `enabled`; the SIP password is only
     * present on creation or rotation and is injected at run time, never stored
     * in a job payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function provisionGatewayLine(array $payload): array;

    /**
     * Remove one gateway line endpoint and its inbound context.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function deprovisionGatewayLine(array $payload): array;

    /**
     * Read the real Asterisk registration snapshot for the given gateway lines.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function gatewayStatus(array $payload): array;

    /**
     * What this implementation can actually do.
     *
     * Used for capability/version negotiation: an agent that predates gateway
     * support answers 501 (or omits the capability), and the UI then says the
     * provisioning service must be updated instead of pretending to be synced.
     *
     * @return array{agent_version: ?string, capabilities: array<int, string>, simulated?: bool}
     */
    public function capabilities(): array;

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
