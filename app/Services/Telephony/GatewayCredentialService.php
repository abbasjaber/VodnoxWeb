<?php

namespace App\Services\Telephony;

use App\Models\Extension;
use App\Models\GatewayLine;
use Illuminate\Support\Str;

/**
 * Generates the SIP identity a GSM gateway line registers with.
 *
 * The gateway is a *classic* SIP/UDP client, not a browser: the credentials are
 * typed into the device by an installer, and are therefore a separate secret
 * from the employee password. The username is globally unique and never the
 * short employee number, so two organizations (or an employee and a gateway)
 * can never collide on the server.
 *
 * The password is encrypted at rest, hidden from serialization, and only ever
 * exposed through an authenticated, no-store endpoint.
 */
class GatewayCredentialService
{
    /**
     * A distinct prefix (vs. the employee "vdx") so a call leg's identity is
     * unambiguous when events are mapped back to a tenant.
     */
    private const USERNAME_PREFIX = 'vdg';

    /**
     * Ensure the line has a SIP identity and the classic server settings.
     *
     * The host/port/transport always come from the gateway profile in config —
     * never from the HTTPS provisioning URL, the AMI address or the WSS URL.
     *
     * @param  bool  $force  regenerate the password (used when rotating)
     */
    public function ensure(GatewayLine $line, bool $force = false): GatewayLine
    {
        $line->fill($this->attributes($line, $force));
        $line->save();

        return $line;
    }

    /**
     * The credential/server attributes for a line *without* persisting them.
     *
     * Used when a line is created, so the row is inserted with a valid global
     * identity from the start (the username column is NOT NULL and unique).
     *
     * @return array<string, mixed>
     */
    public function attributes(GatewayLine $line, bool $force = false): array
    {
        return [
            'sip_username' => ($force || ! $line->sip_username)
                ? $this->uniqueUsername($line)
                : $line->sip_username,
            'sip_password' => ($force || ! $line->sip_password)
                ? $this->password()
                : $line->sip_password,
            'sip_domain' => $line->sip_domain ?: (string) config('integrations.telephony.gateway_realm', ''),
            'sip_host' => $line->sip_host ?: (string) config('integrations.telephony.gateway_sip_host', ''),
            'sip_port' => $line->sip_port ?: (int) config('integrations.telephony.gateway_sip_port', 5060),
            'sip_transport' => $line->sip_transport ?: (string) config('integrations.telephony.gateway_sip_transport', 'udp'),
        ];
    }

    /**
     * A globally unique identifier: prefix + organization + random suffix.
     *
     * Checked against both managed identities so a gateway can never take a
     * username an employee extension already holds.
     */
    public function uniqueUsername(GatewayLine $line): string
    {
        $organizationId = (int) $line->organization_id;

        do {
            $candidate = self::USERNAME_PREFIX.$organizationId.'_'.Str::lower(Str::random(12));
        } while (
            Extension::query()->where('sip_username', $candidate)->exists()
            || GatewayLine::query()->where('sip_username', $candidate)
                ->whereKeyNot($line->getKey() ?? 0)->exists()
        );

        return $candidate;
    }

    /**
     * A strong, config-safe random password (alphanumeric: no escaping issues
     * in PJSIP configuration, INI files or SIP URIs), 190+ bits of entropy.
     */
    public function password(int $length = 32): string
    {
        return Str::random($length);
    }
}
