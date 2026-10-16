<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Services\Telephony\TelephonyProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-user telephony bootstrap.
 *
 * Hands the *current* employee everything the browser needs to register with
 * the telephony server: the connection settings, the directory of colleagues
 * inside their own organization, and — only when their own account is active —
 * their SIP credentials.
 *
 * The response is never cached and never contains another employee's data or
 * any server-side management secret.
 */
class TelephonyController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly TelephonyProvisioningService $provisioning) {}

    public function credentials(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $user = $request->user();

        $extension = $user->primaryExtension();

        $payload = [
            'enabled' => (bool) config('integrations.telephony.enabled'),
            'state' => 'unprovisioned',
            'reason' => null,
            'extension' => $user->extension,
            'sip' => null,
            'directory' => $this->directory($organization, $user->getKey()),
        ];

        if (! $extension) {
            $payload['reason'] = __('portal.agent.telephony_unprovisioned');

            return $this->noStore($payload);
        }

        $payload['extension'] = $extension->extension;

        if (! $organization->isActive()) {
            $payload['state'] = 'disabled';
            $payload['reason'] = __('portal.agent.telephony_org_inactive');

            return $this->noStore($payload);
        }

        if (! $user->active || $extension->disabled_at !== null) {
            $payload['state'] = 'disabled';
            $payload['reason'] = __('portal.agent.telephony_disabled');

            return $this->noStore($payload);
        }

        if ($extension->provisioning_status !== 'synced') {
            $payload['state'] = $extension->provisioning_status === 'failed' ? 'failed' : 'pending';
            $payload['reason'] = $extension->provisioning_error
                ?: __('portal.agent.telephony_provisioning');

            return $this->noStore($payload);
        }

        $payload['state'] = 'ready';
        $payload['sip'] = [
            'username' => $extension->sip_username,
            'password' => (string) $extension->sip_password,
            'domain' => $extension->sip_domain ?: config('integrations.telephony.sip_domain'),
            'wss_url' => config('integrations.telephony.sip_wss_url'),
            'ice' => $this->iceServers(),
        ];

        return $this->noStore($payload);
    }

    /**
     * Ask for a re-sync when provisioning failed or the asset is stale.
     */
    public function provision(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $user = $request->user();

        $extension = $this->provisioning->ensureExtensionForUser($user);

        if (! $extension || $extension->organization_id !== $organization->getKey()) {
            return $this->noStore(['state' => 'unprovisioned'], 422);
        }

        if ($extension->status === 'active') {
            $this->provisioning->syncExtension($extension);
        } else {
            $this->provisioning->disableExtension($extension);
        }

        return $this->noStore(['state' => 'pending'], 202);
    }

    /**
     * Record the browser's last successful registration as a UI hint. The
     * authoritative registration state still comes from the server.
     */
    public function registration(Request $request): JsonResponse
    {
        $request->validate([
            'registered' => ['required', 'boolean'],
        ]);

        $extension = $request->user()->primaryExtension();

        if ($extension) {
            $extension->forceFill([
                'last_registered_at' => $request->boolean('registered') ? now() : null,
            ])->save();
        }

        return $this->noStore(['ok' => true]);
    }

    /**
     * Colleagues inside the organization that the employee may call.
     *
     * @return array<int, array{extension: string, name: string}>
     */
    private function directory($organization, int $excludeUserId): array
    {
        return $organization->extensions()
            ->with('user:id,name')
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $excludeUserId)
            ->where('status', 'active')
            ->where('provisioning_status', 'synced')
            ->whereNull('disabled_at')
            ->orderBy('extension')
            ->get()
            ->map(fn ($extension) => [
                'extension' => (string) $extension->extension,
                'name' => (string) ($extension->user?->name ?? $extension->label ?? $extension->extension),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function iceServers(): array
    {
        $servers = [];

        if ($ice = config('integrations.telephony.sip_ice_url')) {
            $servers[] = ['urls' => $ice];
        }

        if ($turn = config('integrations.telephony.turn_url')) {
            $servers[] = array_filter([
                'urls' => $turn,
                'username' => config('integrations.telephony.turn_username'),
                'credential' => config('integrations.telephony.turn_credential'),
            ]);
        }

        return $servers;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function noStore(array $payload, int $status = 200): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }
}
