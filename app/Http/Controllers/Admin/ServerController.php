<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Telephony\TelephonyGateway;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerRequest;
use App\Models\TelephonyServer;
use App\Services\AuditService;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TelephonyGateway $gateway,
    ) {
    }

    public function index(Request $request)
    {
        $servers = TelephonyServer::latest()->get();

        return view('admin.servers', [
            'servers' => $servers,
            'simulated' => $this->gateway->isSimulated(),
        ]);
    }

    public function store(ServerRequest $request)
    {
        $data = $request->validated();
        $this->dropBlankSecret($data);

        $server = TelephonyServer::create($data);

        $this->audit->log('server.created', $server, [], ['name' => $server->name, 'host' => $server->host]);

        return back()->with('status', __('admin.flash_created'));
    }

    public function update(ServerRequest $request, TelephonyServer $server)
    {
        $data = $request->validated();
        $this->dropBlankSecret($data);

        $old = $server->only(['name', 'host', 'port', 'connection_method', 'status']);
        $server->update($data);

        $this->audit->log('server.updated', $server, $old, $server->only(array_keys($old)));

        return back()->with('status', __('admin.flash_updated'));
    }

    public function destroy(TelephonyServer $server)
    {
        $server->delete();

        $this->audit->log('server.deleted', null, ['name' => $server->name], []);

        return back()->with('status', __('admin.flash_deleted'));
    }

    public function test(Request $request, TelephonyServer $server)
    {
        $result = $this->gateway->testConnection();
        $ok = (bool) ($result['ok'] ?? false);

        $server->update([
            'status' => $ok ? 'connected' : 'error',
            'last_connected_at' => $ok ? now() : $server->last_connected_at,
        ]);

        $this->audit->log('server.tested', $server, [], ['ok' => $ok, 'status' => $server->status]);

        return back()->with('status', $result['message'] ?? __('admin.flash_status'));
    }

    /**
     * Never overwrite a stored secret with an empty value.
     *
     * @param  array<string, mixed>  $data
     */
    private function dropBlankSecret(array &$data): void
    {
        if (empty($data['ami_secret'])) {
            unset($data['ami_secret']);
        }
    }
}
