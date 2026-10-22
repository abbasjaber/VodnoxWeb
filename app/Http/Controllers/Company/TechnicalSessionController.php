<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\TelephonyTechnicalAccount;
use App\Services\AuditService;
use App\Services\Telephony\TechnicalSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The entry point of the "Telephony Technical Settings" section.
 *
 * The section is reachable for the organization's management (the navigation
 * entry exists for whoever may view the gateways), but opening it shows the
 * technical sign-in screen ONLY: no device, port, credential or setup data is
 * loaded into the response before the technical account has authenticated.
 *
 * The sign-in is a real Laravel authentication on its own `technical` guard —
 * not a hidden field and not a JavaScript dialog.
 */
class TechnicalSessionController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private readonly AuditService $audit,
        private readonly TechnicalSessionService $sessions,
    ) {}

    /**
     * Render the technical sign-in screen, or the workspace when the technical
     * session is already live.
     */
    public function show(Request $request)
    {
        $this->authorizePermission($request, 'view_gateways');
        $organization = $this->organization($request);

        if ($this->sessions->isActive($request)) {
            return app(GatewayController::class)->index($request);
        }

        $account = $organization->technicalAccount()->first();

        return view('company.telephony-technical-gate', [
            'org' => $organization,
            'hasAccount' => (bool) ($account && $account->active),
        ]);
    }

    /**
     * Authenticate the technical account.
     *
     * A failure is answered identically for "unknown username", "wrong
     * password", "another organization's account" and "disabled account": the
     * response never reveals whether the username exists.
     */
    public function login(Request $request)
    {
        $this->authorizePermission($request, 'view_gateways');
        $organization = $this->organization($request);

        $data = $request->validate([
            'username' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:190'],
        ]);

        $account = TelephonyTechnicalAccount::query()
            ->where('username', $data['username'])
            ->first();

        $accepted = $account
            && (int) $account->organization_id === (int) $organization->getKey()
            && $account->active
            && Hash::check($data['password'], (string) $account->password);

        if (! $accepted) {
            // The submitted username is recorded so the failed attempt can be
            // investigated; the password never is.
            $this->audit->log(
                'telephony_technical.login_failed',
                null,
                [],
                ['username' => (string) $data['username']],
                $request->user(),
                $organization->getKey(),
                null,
            );

            throw ValidationException::withMessages([
                'username' => [__('telephony_technical.invalid_credentials')],
            ])->status(422);
        }

        // Regenerate before adopting the identity so a fixated session id can
        // never be promoted to an authenticated technical session.
        $request->session()->regenerate();

        auth('technical')->login($account);
        $this->sessions->start($request, $account);

        $account->forceFill(['last_login_at' => now()])->save();

        $this->audit->log(
            'telephony_technical.login',
            null,
            [],
            [
                'username' => $account->username,
                // The account is shared between the organization's support
                // engineers, so the log records the website session it was
                // opened from — never a claim about the human being.
                'opened_by_user_id' => $request->user()?->getKey(),
            ],
            $request->user(),
            $organization->getKey(),
            $account->getKey(),
        );

        return redirect()->route('company.gateways.index')
            ->with('status', __('telephony_technical.session_started'));
    }

    /**
     * End the technical support session. The organization sign-in is untouched.
     */
    public function logout(Request $request)
    {
        $this->authorizePermission($request, 'view_gateways');
        $organization = $this->organization($request);

        $account = $this->sessions->account($request);

        $this->sessions->end($request);

        $this->audit->log(
            'telephony_technical.logout',
            null,
            [],
            ['username' => $account?->username],
            $request->user(),
            $organization->getKey(),
            $account?->getKey(),
        );

        return redirect()->route('company.gateways.index')
            ->with('status', __('telephony_technical.session_ended'));
    }
}
