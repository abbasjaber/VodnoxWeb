<?php

namespace App\Services\Telephony;

use App\Models\TelephonyTechnicalAccount;
use Illuminate\Http\Request;

/**
 * The lifetime and the binding of a "Telephony Technical Settings" session.
 *
 * The session is stored in the shared browser session but is bound to BOTH the
 * technical account identity and the organization it belongs to, and it is
 * always re-validated server side. Consequences, by design:
 *
 *  - a website session alone (even one holding `view_gateway_secrets`) is not
 *    enough: without a technical session every protected route refuses;
 *  - switching or impersonating an organization through the super-admin tools
 *    does not carry the technical session over — the organizations must match;
 *  - resetting the technical password or disabling the account bumps
 *    `session_version`, which instantly invalidates the stored session;
 *  - the session ends after `idle_minutes` without activity and in any case
 *    after `absolute_minutes`, both configurable.
 *
 * Nothing here hides data: it is authentication and authorisation, enforced on
 * the server for every method (GET/POST/PUT/DELETE), AJAX and downloads.
 */
class TechnicalSessionService
{
    public const SESSION_KEY = 'telephony_technical_session';

    /**
     * The technical account behind the current request, if any.
     */
    public function account(Request $request): ?TelephonyTechnicalAccount
    {
        $account = auth('technical')->user();

        return $account instanceof TelephonyTechnicalAccount ? $account : null;
    }

    /**
     * Evaluate the technical session.
     *
     * @return string|null the denial reason, or null when the session is usable
     */
    public function evaluate(Request $request): ?string
    {
        $account = $this->account($request);

        if (! $account) {
            return 'unauthenticated';
        }

        if (! $account->active) {
            return 'disabled';
        }

        $payload = $request->session()->get(self::SESSION_KEY);

        if (! is_array($payload)) {
            return 'unauthenticated';
        }

        if ((int) ($payload['account_id'] ?? 0) !== (int) $account->getKey()) {
            return 'unauthenticated';
        }

        if ((int) ($payload['version'] ?? 0) !== (int) $account->session_version) {
            return 'invalidated';
        }

        if ((int) ($payload['organization_id'] ?? 0) !== (int) $account->organization_id) {
            return 'organization_mismatch';
        }

        // Bind to the organization of the *website* session: a different (or
        // impersonated) organization can never inherit a technical session.
        $webUser = $request->user();

        if (! $webUser || (int) $webUser->organization_id !== (int) $account->organization_id) {
            return 'organization_mismatch';
        }

        $config = (array) config('integrations.telephony.technical_session', []);
        $idleMinutes = max(1, (int) ($config['idle_minutes'] ?? 15));
        $absoluteMinutes = max(1, (int) ($config['absolute_minutes'] ?? 120));

        $now = now()->timestamp;

        if ($now - (int) ($payload['started_at'] ?? 0) > $absoluteMinutes * 60) {
            return 'expired';
        }

        if ($now - (int) ($payload['last_activity_at'] ?? 0) > $idleMinutes * 60) {
            return 'idle';
        }

        return null;
    }

    public function isActive(Request $request): bool
    {
        return $this->evaluate($request) === null;
    }

    /**
     * Start a technical session for a freshly authenticated account.
     */
    public function start(Request $request, TelephonyTechnicalAccount $account): void
    {
        $now = now()->timestamp;

        $request->session()->put(self::SESSION_KEY, [
            'account_id' => $account->getKey(),
            'organization_id' => $account->organization_id,
            'version' => $account->session_version,
            'started_at' => $now,
            'last_activity_at' => $now,
            // The website account the session was opened from; the technical
            // account is shared, so this is what the log can honestly say.
            'opened_by_user_id' => $request->user()?->getKey(),
        ]);
    }

    /**
     * Mark activity so the idle window slides.
     */
    public function touch(Request $request): void
    {
        $payload = $request->session()->get(self::SESSION_KEY);

        if (! is_array($payload)) {
            return;
        }

        $payload['last_activity_at'] = now()->timestamp;

        $request->session()->put(self::SESSION_KEY, $payload);
    }

    /**
     * The website account that opened the session, as stored at sign-in.
     */
    public function openedBy(Request $request): ?int
    {
        $payload = $request->session()->get(self::SESSION_KEY);

        return is_array($payload) ? ($payload['opened_by_user_id'] ?? null) : null;
    }

    /**
     * End the technical session and forget its state.
     */
    public function end(Request $request): void
    {
        if (auth('technical')->check()) {
            auth('technical')->logout();
        }

        $request->session()->forget(self::SESSION_KEY);
    }
}
