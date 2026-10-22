<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\TelephonyTechnicalAccount;
use App\Services\AuditService;
use App\Services\Telephony\TelephonyTechnicalAccountService;
use Illuminate\Http\Request;

/**
 * The super admin's control of one organization's "Telephony Technical
 * Settings" sign-in.
 *
 * Only the platform team may reach these actions. The organization
 * administrator can never read, change or reset this account — it is not
 * exposed in the company workspace at all.
 *
 * The clear password exists for exactly one response: the one that shows it
 * after creation or reset. It is never flashed through the session, never
 * logged, and cannot be retrieved again afterwards.
 */
class TechnicalAccountController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TelephonyTechnicalAccountService $accounts,
    ) {}

    /**
     * Prepare (or re-create) the technical sign-in of an organization that
     * predates the section. An existing account is left untouched.
     */
    public function store(Organization $organization)
    {
        $existing = $organization->technicalAccount()->first();

        if ($existing) {
            return redirect()
                ->route('admin.organizations.show', $organization)
                ->with('status', __('admin.tech_exists'));
        }

        $result = $this->accounts->createFor($organization, auth()->id());

        $this->audit->log(
            'telephony_technical.account_created',
            $organization,
            [],
            ['username' => $result['account']->username],
            null,
            $organization->id,
        );

        return $this->showCredentials($organization, $result['account'], $result['password'], created: true);
    }

    /**
     * Issue a new password. Every technical session of the organization is
     * invalidated; the previous password cannot be recovered — only replaced.
     */
    public function reset(Organization $organization)
    {
        $account = $organization->technicalAccount()->first();

        abort_unless($account instanceof TelephonyTechnicalAccount, 404);

        $result = $this->accounts->resetPassword($account);

        $this->audit->log(
            'telephony_technical.account_reset',
            $organization,
            [],
            ['username' => $account->username],
            null,
            $organization->id,
        );

        return $this->showCredentials($organization, $result['account'], $result['password'], created: false);
    }

    /**
     * Enable/disable the sign-in. Both transitions invalidate open sessions.
     */
    public function updateStatus(Request $request, Organization $organization)
    {
        $account = $organization->technicalAccount()->first();

        abort_unless($account instanceof TelephonyTechnicalAccount, 404);

        $data = $request->validate(['active' => ['required', 'boolean']]);
        $active = (bool) $data['active'];

        $this->accounts->setActive($account, $active);

        $this->audit->log(
            $active ? 'telephony_technical.account_enabled' : 'telephony_technical.account_disabled',
            $organization,
            ['active' => ! $active],
            ['active' => $active, 'username' => $account->username],
            null,
            $organization->id,
        );

        return redirect()
            ->route('admin.organizations.show', ['organization' => $organization, 'tab' => 'technical'])
            ->with('status', __('admin.flash_updated'));
    }

    /**
     * The one and only response that ever carries the clear password.
     */
    private function showCredentials(Organization $organization, TelephonyTechnicalAccount $account, string $password, bool $created)
    {
        return response()->view('admin.organizations.technical-credentials', [
            'organization' => $organization,
            'account' => $account,
            'password' => $password,
            'created' => $created,
        ])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
