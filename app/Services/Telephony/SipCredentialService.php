<?php

namespace App\Services\Telephony;

use App\Models\Extension;
use Illuminate\Support\Str;

/**
 * Generates the SIP identity an employee's browser uses to register.
 *
 * The SIP username is globally unique (never the short extension alone), while
 * the short extension stays the organization-facing number. The password is a
 * separate secret from the website password and is only ever exposed to the
 * owning user through an authenticated, no-store endpoint.
 */
class SipCredentialService
{
    private const USERNAME_PREFIX = 'vdx';

    /**
     * Ensure the extension has a SIP identity; generate one when missing.
     *
     * @param  bool  $force  regenerate the password (used when rotating)
     */
    public function ensure(Extension $extension, bool $force = false): Extension
    {
        if ($force || ! $extension->sip_username) {
            $extension->sip_username = $this->uniqueUsername($extension);
        }

        if ($force || ! $extension->sip_password) {
            $extension->sip_password = $this->password();
        }

        if (! $extension->sip_domain) {
            $extension->sip_domain = (string) config('integrations.telephony.sip_domain', '');
        }

        $extension->save();

        return $extension;
    }

    /**
     * A globally unique identifier: prefix + organization + random suffix.
     *
     * It deliberately does not depend on the short extension, so two
     * organizations may both use "1001" without colliding on the server.
     */
    public function uniqueUsername(Extension $extension): string
    {
        $org = (int) $extension->organization_id;

        do {
            $candidate = self::USERNAME_PREFIX.$org.'_'.Str::lower(Str::random(12));
        } while (
            Extension::query()->where('sip_username', $candidate)->exists()
            || Extension::query()->whereKeyNot($extension->getKey() ?? 0)->where('sip_username', $candidate)->exists()
        );

        return $candidate;
    }

    /**
     * A strong, config-safe random password (alphanumeric: no escaping issues
     * in PJSIP configuration or SIP URIs) with 190+ bits of entropy.
     */
    public function password(int $length = 32): string
    {
        return Str::random($length);
    }
}
