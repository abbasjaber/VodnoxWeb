<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GatewayDevice;
use App\Services\Telephony\GatewayProvisioningService;
use Illuminate\Http\Request;

/**
 * Super-admin surveillance of GSM gateway lines across every organization.
 *
 * Read-only on purpose: the platform administrator can see the honest state
 * (provisioning, registration, staleness) and trigger a status refresh, but
 * never reads a SIP secret and never edits another tenant's routing. This is
 * the "super admin tool" entry for gateways and is unreachable by an
 * organization employee.
 */
class GatewayController extends Controller
{
    public function __construct(
        private readonly GatewayProvisioningService $gateways,
    ) {}

    public function index(Request $request)
    {
        $devices = GatewayDevice::query()
            ->with(['organization:id,name_ar,name_en', 'lines.destination'])
            ->orderBy('organization_id')
            ->orderBy('label')
            ->get();

        return view('admin.gateways', [
            'devices' => $devices,
            'supportsGateways' => $this->gateways->agentSupportsGateways(),
            'simulated' => $this->gateways->isSimulated(),
        ]);
    }

    public function refresh(Request $request, GatewayDevice $gateway)
    {
        $result = $this->gateways->refreshStatus($gateway);

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? __('admin.gw_status_refreshed') : __('admin.gw_monitoring_unavailable'),
        );
    }
}
