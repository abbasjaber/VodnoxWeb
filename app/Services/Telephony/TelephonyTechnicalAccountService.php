<?php

namespace App\Services\Telephony;

use App\Models\Organization;
use App\Models\TelephonyTechnicalAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns the independent technical sign-in of one organization's "Telephony
 * Technical Settings" section.
 *
 * Design rules enforced here:
 *  - one account per organization, never a shared support login and never a
 *    password reused across organizations;
 *  - the password is one-way hashed by the model's `hashed` cast and is
 *    returned in clear ONLY at the moment it is generated, so the super admin
 *    can hand it over. It is never stored, logged or rendered again;
 *  - resetting the password or disabling the account bumps `session_version`,
 *    which is what invalidates every technical session already open;
 *  - the account is completely separate from the organization administrator's
 *    website account, and it can never reach another organization.
 */
class TelephonyTechnicalAccountService
{
    /** A distinct prefix so a technical sign-in is never confused with staff. */
    private const USERNAME_PREFIX = 'vdt';

    /**
     * Create the technical account of an organization.
     *
     * The clear password is returned once and is not part of the persisted
     * model in any recoverable form.
     *
     * @return array{account: TelephonyTechnicalAccount, password: string}
     */
    public function createFor(Organization $organization, ?int $createdBy = null): array
    {
        $password = $this->password();

        $account = TelephonyTechnicalAccount::updateOrCreate(
            ['organization_id' => $organization->getKey()],
            [
                'created_by' => $createdBy,
                'username' => $this->uniqueUsername(),
                'password' => $password,
                'active' => true,
                'session_version' => 1,
                'password_changed_at' => now(),
                'last_login_at' => null,
                'disabled_at' => null,
            ],
        );

        return ['account' => $account, 'password' => $password];
    }

    /**
     * Issue a new password. The previous one cannot be recovered — only
     * replaced — and every open technical session is invalidated.
     *
     * @return array{account: TelephonyTechnicalAccount, password: string}
     */
    public function resetPassword(TelephonyTechnicalAccount $account): array
    {
        $password = $this->password();

        $account->forceFill([
            'password' => $password,
            'password_changed_at' => now(),
            'session_version' => (int) $account->session_version + 1,
        ])->save();

        return ['account' => $account, 'password' => $password];
    }

    /**
     * Enable/disable the sign-in. Both transitions invalidate open sessions.
     */
    public function setActive(TelephonyTechnicalAccount $account, bool $active): void
    {
        $account->forceFill([
            'active' => $active,
            'disabled_at' => $active ? null : now(),
            'session_version' => (int) $account->session_version + 1,
        ])->save();
    }

    /**
     * Ensure every organization has an account, without touching one that
     * already exists (used to prepare organizations created before the section
     * existed — their administrators and connections are left untouched).
     *
     * @return array{account: TelephonyTechnicalAccount, password: ?string, created: bool}
     */
    public function ensureFor(Organization $organization, ?int $createdBy = null): array
    {
        $existing = $organization->technicalAccount()->first();

        if ($existing) {
            return ['account' => $existing, 'password' => null, 'created' => false];
        }

        $created = DB::transaction(fn () => $this->createFor($organization, $createdBy));

        return $created + ['created' => true];
    }

    /**
     * A globally unique, non-guessable sign-in name.
     */
    public function uniqueUsername(): string
    {
        do {
            $candidate = self::USERNAME_PREFIX.'_'.Str::lower(Str::random(10));
        } while (TelephonyTechnicalAccount::query()->where('username', $candidate)->exists());

        return $candidate;
    }

    /**
     * A strong random password. Letters and digits only: it is copied by hand
     * from the super-admin screen, so ambiguous shell/URL escaping is avoided.
     */
    public function password(int $length = 24): string
    {
        return Str::password($length, symbols: false);
    }
}
