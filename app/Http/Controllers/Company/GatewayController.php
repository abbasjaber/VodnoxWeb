<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\GatewayDeviceRequest;
use App\Models\GatewayDevice;
use App\Models\GatewayLine;
use App\Services\AuditService;
use App\Services\Telephony\GatewayProvisioningService;
use Illuminate\Http\Request;

/**
 * Organization workspace: GSM gateway lines.
 *
 * An organization administrator adds a GoIP (or similar) device from this page;
 * the server then allocates an independent SIP identity and an independent
 * inbound route per line. Nothing here is reachable by a plain employee, and
 * the SIP secret needs its *own* permission — being an organization supervisor
 * is deliberately not enough.
 */
class GatewayController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private readonly AuditService $audit,
        private readonly GatewayProvisioningService $gateways,
    ) {}

    public function index(Request $request)
    {
        $this->authorizePermission($request, 'view_gateways');
        $org = $this->organization($request);

        $devices = $org->gatewayDevices()
            ->with(['lines.route.defaultDestination', 'lines.destination'])
            ->orderBy('label')
            ->get();

        $capabilities = $this->gateways->capabilities();
        $supportsGateways = $this->gateways->agentSupportsGateways();

        return view('company.gateways', [
            'org' => $org,
            'devices' => $devices,
            'profiles' => $this->gateways->profiles(),
            'serverSettings' => $this->gateways->serverSettings(),
            'supportsGateways' => $supportsGateways,
            'supportsRouting' => $this->gateways->agentSupportsRouting(),
            'simulated' => $this->gateways->isSimulated(),
            'agentVersion' => $capabilities['agent_version'] ?? null,
            'canManage' => $request->user()->hasPermission('manage_gateways'),
            'canViewSecret' => $request->user()->hasPermission('view_gateway_secrets'),
            'canRotateSecret' => $request->user()->hasPermission('rotate_gateway_secrets'),
        ]);
    }

    public function store(GatewayDeviceRequest $request)
    {
        $this->authorizePermission($request, 'manage_gateways');
        $org = $this->organization($request);
        $data = $request->validated();

        $device = $this->gateways->createDevice(
            $org,
            $request->user()->getKey(),
            [
                'label' => $data['label'],
                'model' => $data['model'],
                'firmware' => $data['firmware'] ?? null,
                'network_notes' => $data['network_notes'] ?? null,
                'enabled' => (bool) ($data['enabled'] ?? true),
            ],
            $data['lines'] ?? [],
        );

        // Audit the topology only; SIP credentials are never written here.
        $this->audit->log(
            'gateway.created',
            $device,
            [],
            [
                'label' => $device->label,
                'model' => $device->model,
                'ports' => $device->lines()->get(['line_index', 'enabled'])->toArray(),
            ],
            null,
            $org->id,
        );

        return redirect()->route('company.gateways.index')
            ->with('status', __('company.gateway_created'));
    }

    public function update(GatewayDeviceRequest $request, GatewayDevice $gateway)
    {
        $this->authorizePermission($request, 'manage_gateways');
        $org = $this->organization($request);
        $this->authorizeTenant($org, $gateway);

        $data = $request->validated();
        $old = $gateway->only(['label', 'firmware', 'network_notes']);

        $gateway->fill([
            'label' => $data['label'],
            'firmware' => $data['firmware'] ?? null,
            'network_notes' => $data['network_notes'] ?? null,
        ])->save();

        $this->audit->log('gateway.updated', $gateway, $old, $gateway->only(['label', 'firmware', 'network_notes']), null, $org->id);

        return redirect()->route('company.gateways.index')->with('status', __('company.updated'));
    }

    public function destroy(Request $request, GatewayDevice $gateway)
    {
        $this->authorizePermission($request, 'manage_gateways');
        $org = $this->organization($request);
        $this->authorizeTenant($org, $gateway);

        $this->audit->log('gateway.deleted', $gateway, ['label' => $gateway->label, 'model' => $gateway->model], [], null, $org->id);

        $this->gateways->deleteDevice($gateway);

        return redirect()->route('company.gateways.index')->with('status', __('company.deleted'));
    }

    /**
     * Re-run provisioning for a device (all of its lines).
     */
    public function retry(Request $request, GatewayDevice $gateway)
    {
        $this->authorizePermission($request, 'manage_gateways');
        $org = $this->organization($request);
        $this->authorizeTenant($org, $gateway);

        $this->gateways->syncDevice($gateway);

        $this->audit->log('gateway.retried', $gateway, [], ['label' => $gateway->label], null, $org->id);

        return redirect()->route('company.gateways.index')->with('status', __('company.gateway_retry_queued'));
    }

    /**
     * Read the real Asterisk registration snapshot. Read-only, so it is gated
     * by the view permission rather than the manage one.
     */
    public function refresh(Request $request, GatewayDevice $gateway)
    {
        $this->authorizePermission($request, 'view_gateways');
        $org = $this->organization($request);
        $this->authorizeTenant($org, $gateway);

        $result = $this->gateways->refreshStatus($gateway);

        if ($result['ok']) {
            return redirect()->route('company.gateways.index')
                ->with('status', __('company.gateway_status_refreshed'));
        }

        $key = match ($result['message'] ?? null) {
            'agent_update_required' => 'company.gateway_monitoring_agent_old',
            'monitoring_unavailable' => 'company.gateway_monitoring_unavailable',
            'no_lines' => 'company.gateway_no_lines',
            default => 'company.gateway_monitoring_unavailable',
        };

        return redirect()->route('company.gateways.index')->with('error', __($key));
    }

    /**
     * The unified "link the device" page for one device.
     *
     * Everything an installer has to type into the device lives here, in one
     * place: the settings shared by the whole device (SIP host/port/transport)
     * and the per-port settings the model needs (authentication id, password,
     * phone number). Each port is labelled next to its own values so a line's
     * credentials can never be copied onto another line by mistake.
     *
     * The password itself is never rendered: it is revealed on demand through
     * the secret endpoint, which has its own permission.
     */
    public function settings(Request $request, GatewayDevice $gateway)
    {
        $this->authorizePermission($request, 'view_gateways');
        $org = $this->organization($request);
        $this->authorizeTenant($org, $gateway);

        $gateway->load('lines.route.defaultDestination');

        return view('company.gateway-settings', [
            'org' => $org,
            'gateway' => $gateway,
            'serverSettings' => $this->gateways->serverSettings(),
            'profile' => $this->gateways->profiles()[$gateway->model] ?? null,
            'supportsRouting' => $this->gateways->agentSupportsRouting(),
            'canManage' => $request->user()->hasPermission('manage_gateways'),
            'canViewSecret' => $request->user()->hasPermission('view_gateway_secrets'),
        ]);
    }

    /**
     * Enable/disable a device; its lines follow and are re-provisioned for real
     * on re-enable (the identity is kept, the server state is rebuilt).
     */
    public function toggle(Request $request, GatewayDevice $gateway)
    {
        $this->authorizePermission($request, 'manage_gateways');
        $org = $this->organization($request);
        $this->authorizeTenant($org, $gateway);

        $enabled = ! $gateway->enabled;
        $this->gateways->setDeviceEnabled($gateway, $enabled);

        $this->audit->log('gateway.toggled', $gateway, ['enabled' => ! $enabled], ['enabled' => $enabled], null, $org->id);

        return redirect()->route('company.gateways.index')->with('status', __('company.updated'));
    }

    /**
     * Update the descriptive data of one port and whether it is in use.
     *
     * The inbound destination is deliberately NOT here any more: it belongs to
     * the numbers & routing workspace, which owns the whole routing rule set.
     * Marking a port "in use" is what makes it eligible for provisioning; a
     * port that is off is never dialled and never assumed to carry a SIM.
     */
    public function updateLine(Request $request, GatewayDevice $gateway, GatewayLine $line)
    {
        $this->authorizePermission($request, 'manage_gateways');
        $org = $this->organization($request);
        $this->authorizeTenant($org, $gateway);
        $this->authorizeLine($gateway, $line);

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'sim_number' => ['nullable', 'string', 'max:40'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $old = $line->only(['label', 'sim_number', 'enabled']);

        $inUse = (bool) ($data['enabled'] ?? $line->enabled);

        $line->fill([
            'label' => $data['label'] ?? null,
            'sim_number' => $data['sim_number'] ?? null,
        ])->save();

        $this->audit->log('gateway.line.updated', $line, $old, $line->only(['label', 'sim_number', 'enabled']), null, $org->id);

        // Toggling the port and editing its description are one action from the
        // administrator's point of view; re-provisioning keeps the identity and
        // never rotates the secret.
        if ($inUse !== (bool) $old['enabled']) {
            $this->gateways->setLineEnabled($line, $inUse);
        } else {
            $this->gateways->syncLine($line);
        }

        return redirect()->route('company.gateways.index')->with('status', __('company.updated'));
    }

    /**
     * Rotate the SIP password of a line and push the new secret to the server.
     */
    public function rotate(Request $request, GatewayDevice $gateway, GatewayLine $line)
    {
        $this->authorizePermission($request, 'rotate_gateway_secrets');
        $org = $this->organization($request);
        $this->authorizeTenant($org, $gateway);
        $this->authorizeLine($gateway, $line);

        $this->gateways->rotateLineSecret($line);

        $this->audit->log('gateway.secret_rotated', $line, [], ['sip_username' => $line->sip_username], null, $org->id);

        return redirect()->route('company.gateways.index')
            ->with('status', __('company.gateway_secret_rotated'));
    }

    /**
     * Reveal the line credentials on demand.
     *
     * Gated by its own permission (a supervisor does not hold it), never
     * cached, and every reveal is audited — without the secret itself.
     */
    public function secret(Request $request, GatewayDevice $gateway, GatewayLine $line)
    {
        $this->authorizePermission($request, 'view_gateway_secrets');
        $org = $this->organization($request);
        $this->authorizeTenant($org, $gateway);
        $this->authorizeLine($gateway, $line);

        $this->audit->log('gateway.secret_viewed', $line, [], ['sip_username' => $line->sip_username], null, $org->id);

        return response()->json([
            'sip_username' => $line->sip_username,
            'sip_password' => (string) $line->sip_password,
            'sip_host' => $line->sip_host,
            'sip_port' => $line->sip_port,
            'sip_transport' => $line->sip_transport,
            'sip_domain' => $line->sip_domain,
        ])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * A line must belong to the bound device *and* to the organization, so a
     * tampered line id can never reach another tenant's line.
     */
    private function authorizeLine(GatewayDevice $gateway, GatewayLine $line): void
    {
        abort_unless((int) $line->gateway_device_id === (int) $gateway->getKey(), 404);
    }
}
