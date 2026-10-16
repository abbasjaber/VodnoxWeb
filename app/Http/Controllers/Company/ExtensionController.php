<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\ExtensionRequest;
use App\Jobs\ProvisionTelephonyJob;
use App\Models\Extension;
use App\Models\Organization;
use App\Services\AuditService;
use Illuminate\Http\Request;

class ExtensionController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request)
    {
        $org = $this->organization($request);

        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $extensions = $org->extensions()
            ->with('user')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('extension', 'like', "%{$q}%")
                        ->orWhere('label', 'like', "%{$q}%");
                });
            })
            ->when(in_array($status, ['inactive', 'active', 'provisioning', 'provisioned', 'failed'], true), fn ($query) => $query->where('status', $status))
            ->orderBy('extension')
            ->paginate(15)
            ->withQueryString();

        $users = $org->users()->where('active', true)->orderBy('name')->get();

        return view('company.extensions', compact('org', 'extensions', 'users', 'q', 'status'));
    }

    public function store(ExtensionRequest $request)
    {
        $org = $this->organization($request);
        $data = $request->validated();

        $extension = $org->extensions()->create([
            'user_id' => $data['user_id'] ?? null,
            'extension' => $data['extension'],
            'label' => $data['label'] ?? null,
            'status' => $data['status'] ?? 'provisioning',
        ]);

        $this->audit->log('extension.created', $extension, [], $extension->only(['extension', 'label', 'user_id', 'status']), null, $org->id);
        $this->dispatchProvisioning($org, $extension);

        return redirect()->route('company.extensions.index')->with('status', __('company.created'));
    }

    public function update(ExtensionRequest $request, Extension $extension)
    {
        $org = $this->organization($request);
        $this->authorizeTenant($org, $extension);

        $data = $request->validated();
        $old = $extension->only(['extension', 'label', 'user_id', 'status']);

        $extension->fill([
            'user_id' => $data['user_id'] ?? null,
            'extension' => $data['extension'],
            'label' => $data['label'] ?? null,
            'status' => $data['status'] ?? $extension->status,
        ])->save();

        $this->audit->log('extension.updated', $extension, $old, $extension->only(['extension', 'label', 'user_id', 'status']), null, $org->id);
        $this->dispatchProvisioning($org, $extension);

        return redirect()->route('company.extensions.index')->with('status', __('company.updated'));
    }

    public function destroy(Request $request, Extension $extension)
    {
        $org = $this->organization($request);
        $this->authorizeTenant($org, $extension);

        $this->audit->log('extension.deleted', $extension, ['extension' => $extension->extension, 'label' => $extension->label], [], null, $org->id);

        $extension->delete();

        return redirect()->route('company.extensions.index')->with('status', __('company.deleted'));
    }

    /**
     * Reconcile the extension with the telephony server (idempotent per content).
     */
    private function dispatchProvisioning(Organization $org, Extension $extension): void
    {
        $payload = [
            'organization_id' => $org->id,
            'extension_id' => $extension->id,
            'extension' => $extension->extension,
            'label' => $extension->label,
            'user_id' => $extension->user_id,
            'status' => $extension->status,
        ];

        $key = 'extension:'.$extension->id.':'.sha1((string) json_encode($payload));

        ProvisionTelephonyJob::dispatch('provision_extension', $org->id, $payload, $key, $extension->id);
    }
}
