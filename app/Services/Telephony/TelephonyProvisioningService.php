<?php

namespace App\Services\Telephony;

use App\Jobs\ProvisionTelephonyJob;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;

/**
 * Orchestrates real telephony provisioning between VODNOX and the Asterisk
 * provisioning agent.
 *
 * Responsibilities:
 *  - keep a stable organization context/realm used for dialplan isolation;
 *  - allocate a unique internal number per organization (two organizations may
 *    both use "1001");
 *  - create the SIP identity (globally unique username + separate password);
 *  - reconcile the server through idempotent, retried jobs.
 *
 * Secrets are never placed in the job payload: the payload carries the
 * extension id and the job reads the (encrypted) credentials at execution
 * time, so provisioning_jobs never stores a SIP password.
 */
class TelephonyProvisioningService
{
    private const FIRST_EXTENSION = 1000;

    public function __construct(private readonly SipCredentialService $credentials) {}

    /**
     * A stable, server-side context for the organization's dialplan isolation.
     */
    public function ensureOrganizationContext(Organization $organization): Organization
    {
        if (! $organization->telephony_context) {
            $organization->forceFill([
                'telephony_context' => 'vodnox_org_'.$organization->getKey(),
            ]);
        }

        if (! $organization->telephony_realm) {
            $realm = (string) config('integrations.telephony.sip_domain', '');
            $organization->telephony_realm = $realm !== '' ? $realm : null;
        }

        if ($organization->isDirty()) {
            $organization->save();
        }

        return $organization;
    }

    /**
     * The smallest free internal number (>= 1000) inside the organization.
     */
    public function allocateExtensionNumber(Organization $organization): string
    {
        $taken = Extension::query()
            ->where('organization_id', $organization->getKey())
            ->pluck('extension')
            ->map(fn ($value) => (int) $value)
            ->all();

        $taken = array_flip($taken);
        $candidate = self::FIRST_EXTENSION;

        while (isset($taken[$candidate])) {
            $candidate++;
        }

        return (string) $candidate;
    }

    /**
     * Ensure an active employee has an extension asset wired to their account.
     */
    public function ensureExtensionForUser(User $user, ?string $number = null): ?Extension
    {
        if (! $user->organization_id) {
            return null;
        }

        $organization = $user->organization;

        if (! $organization) {
            return null;
        }

        $number = $number !== null ? trim($number) : '';
        $number = $number !== '' ? $number : (string) ($user->extension ?? '');

        $extension = Extension::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        // Reattach by number when the admin typed an existing free number.
        if (! $extension && $number !== '') {
            $extension = Extension::query()
                ->where('organization_id', $organization->getKey())
                ->where('extension', $number)
                ->whereNull('user_id')
                ->first();
        }

        if (! $extension) {
            $extension = new Extension([
                'organization_id' => $organization->getKey(),
                'extension' => $number !== '' ? $number : $this->allocateExtensionNumber($organization),
                'status' => $user->active ? 'active' : 'inactive',
                'provisioning_status' => 'pending',
            ]);
        }

        $extension->user_id = $user->getKey();
        $extension->status = $user->active ? 'active' : 'inactive';
        $extension->label = $extension->label ?: $user->name;
        $extension->save();

        // Mirror the number on the user row so the portal keeps displaying it.
        if ((string) $user->extension !== (string) $extension->extension) {
            $user->forceFill(['extension' => $extension->extension])->save();
        }

        $this->ensureOrganizationContext($organization);
        $this->credentials->ensure($extension);

        return $extension->refresh();
    }

    /**
     * Reconcile the organization and every extension under it.
     *
     * Active, telephony-eligible employees without an extension asset get one
     * allocated first, so activating an organization makes its staff dialable
     * without any manual SIP work.
     */
    public function syncOrganization(Organization $organization): void
    {
        $this->ensureOrganizationContext($organization);

        $organization->forceFill([
            'telephony_status' => 'pending',
            'telephony_error' => null,
        ])->save();

        $payload = $this->organizationPayload($organization);
        $key = 'organization:'.$organization->getKey().':'.sha1((string) json_encode($payload));

        ProvisionTelephonyJob::dispatch('provision_organization', $organization->getKey(), $payload, $key);

        $organization->users()->where('active', true)->get()
            ->filter(fn (User $user) => $this->isEligible($user))
            ->each(fn (User $user) => $this->ensureExtensionForUser($user));

        Extension::query()
            ->where('organization_id', $organization->getKey())
            ->get()
            ->each(fn (Extension $extension) => $this->syncExtension($extension));
    }

    /**
     * Only staff who can actually use the phone portal get a SIP account.
     */
    public function isEligible(User $user): bool
    {
        return in_array($user->role?->name, ['organization_admin', 'supervisor', 'agent'], true);
    }

    /**
     * Reconcile a single extension (identity + server account + dialplan line).
     */
    public function syncExtension(Extension $extension): void
    {
        $organization = $extension->organization;

        if (! $organization) {
            return;
        }

        $this->ensureOrganizationContext($organization);
        $this->credentials->ensure($extension);
        $extension->refresh();

        $extension->forceFill([
            'provisioning_status' => 'pending',
            'provisioning_error' => null,
        ])->save();

        $payload = $this->extensionPayload($extension, $organization);

        ProvisionTelephonyJob::dispatch(
            'provision_extension',
            $organization->getKey(),
            $payload,
            $this->extensionKey($extension, $payload),
            $extension->getKey(),
        );
    }

    /**
     * Disable an extension on the telephony server (registration + new calls).
     */
    public function disableExtension(Extension $extension): void
    {
        $organization = $extension->organization;

        if (! $organization) {
            return;
        }

        $extension->forceFill([
            'provisioning_status' => 'pending',
            'provisioning_error' => null,
            'disabled_at' => now(),
        ])->save();

        $payload = $this->extensionPayload($extension, $organization, enabled: false);

        ProvisionTelephonyJob::dispatch(
            'provision_extension',
            $organization->getKey(),
            $payload,
            $this->extensionKey($extension, $payload),
            $extension->getKey(),
        );
    }

    /**
     * Remove an extension from the telephony server (identity is not reused).
     */
    public function deprovisionExtension(Extension $extension): void
    {
        $organization = $extension->organization;

        if (! $organization || ! $extension->sip_username) {
            return;
        }

        $payload = [
            'organization_id' => $organization->getKey(),
            'sip_username' => $extension->sip_username,
            'context' => $organization->telephony_context,
        ];

        ProvisionTelephonyJob::dispatch(
            'deprovision_extension',
            $organization->getKey(),
            $payload,
            'deprovision:extension:'.$extension->getKey().':'.$extension->sip_username,
            $extension->getKey(),
        );
    }

    /**
     * Disable an organization's whole context (blocks every account under it).
     */
    public function syncOrganizationEnabledState(Organization $organization): void
    {
        if ($organization->isActive()) {
            $this->syncOrganization($organization);

            return;
        }

        $this->ensureOrganizationContext($organization);

        $organization->forceFill([
            'telephony_status' => 'pending',
            'telephony_error' => null,
        ])->save();

        $payload = $this->organizationPayload($organization, enabled: false);
        $key = 'organization:'.$organization->getKey().':disabled:'.sha1((string) json_encode($payload));

        ProvisionTelephonyJob::dispatch('provision_organization', $organization->getKey(), $payload, $key);

        $organization->extensions()->each(fn (Extension $extension) => $this->disableExtension($extension));
    }

    /**
     * Remove an organization's context and every account under it.
     */
    public function deprovisionOrganization(Organization $organization): void
    {
        $payload = [
            'organization_id' => $organization->getKey(),
            'context' => $organization->telephony_context ?: 'vodnox_org_'.$organization->getKey(),
            'code' => $organization->code,
        ];

        ProvisionTelephonyJob::dispatch(
            'deprovision_organization',
            $organization->getKey(),
            $payload,
            'deprovision:organization:'.$organization->getKey(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function organizationPayload(Organization $organization, bool $enabled = true): array
    {
        return [
            'organization_id' => $organization->getKey(),
            'code' => $organization->code,
            'name' => $organization->name_en ?: $organization->name_ar,
            'context' => $organization->telephony_context,
            'realm' => $organization->telephony_realm,
            'enabled' => $enabled,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function extensionPayload(Extension $extension, Organization $organization, bool $enabled = true): array
    {
        return [
            'organization_id' => $organization->getKey(),
            'extension_id' => $extension->getKey(),
            'context' => $organization->telephony_context,
            'extension' => $extension->extension,
            'sip_username' => $extension->sip_username,
            'sip_domain' => $extension->sip_domain,
            'label' => $extension->label,
            'user_id' => $extension->user_id,
            'enabled' => $enabled && $extension->status === 'active' && $organization->isActive(),
        ];
    }

    /**
     * The key includes a non-reversible digest of the password so rotating the
     * secret re-syncs the server without ever persisting the secret itself.
     *
     * @param  array<string, mixed>  $payload
     */
    private function extensionKey(Extension $extension, array $payload): string
    {
        $digest = substr(sha1('sip:'.(string) $extension->sip_password), 0, 16);

        return 'extension:'.$extension->getKey().':'.$digest.':'.substr(sha1((string) json_encode($payload)), 0, 16);
    }
}
