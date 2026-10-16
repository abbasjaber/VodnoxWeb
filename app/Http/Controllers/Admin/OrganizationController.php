<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrganizationRequest;
use App\Http\Requests\Admin\OrganizationStatusRequest;
use App\Models\ActivationRequest;
use App\Models\AuditLog;
use App\Models\CallQueue;
use App\Models\Invoice;
use App\Models\IvrMenu;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\Recording;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Telephony\TelephonyProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganizationController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TelephonyProvisioningService $provisioning,
    ) {
    }

    public function index(Request $request)
    {
        $query = Organization::query()->withCount('users');

        if ($q = trim((string) $request->query('q'))) {
            $query->where(function ($sub) use ($q) {
                $sub->where('name_ar', 'like', "%{$q}%")
                    ->orWhere('name_en', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        if ($status = $request->query('status')) {
            if (in_array($status, ['pending', 'active', 'trial', 'suspended', 'expired'], true)) {
                $query->where('status', $status);
            }
        }

        $organizations = $query->latest()->paginate(15)->withQueryString();

        return view('admin.organizations.index', [
            'organizations' => $organizations,
            'q' => $request->query('q', ''),
            'status' => $request->query('status', ''),
        ]);
    }

    public function store(OrganizationRequest $request)
    {
        $data = $request->validated();

        $organization = DB::transaction(function () use ($data) {
            $organization = Organization::create([
                'code' => $data['code'],
                'name_ar' => $data['name_ar'],
                'name_en' => $data['name_en'],
                'contact_name' => $data['contact_name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'city' => $data['city'] ?? null,
                'status' => $data['status'],
                'timezone' => $data['timezone'] ?? 'Asia/Baghdad',
                'language' => $data['language'],
                'internal_notes' => $data['internal_notes'] ?? null,
                'activated_at' => in_array($data['status'], ['active', 'trial'], true) ? now() : null,
            ]);

            $role = Role::where('name', 'organization_admin')->first();

            User::create([
                'organization_id' => $organization->id,
                'role_id' => $role?->id,
                'name' => $data['admin_name'],
                'username' => $data['admin_username'],
                'email' => $data['admin_email'] ?? null,
                'password' => $data['admin_password'],
                'active' => true,
            ]);

            return $organization;
        });

        $this->audit->log('organization.created', $organization, [], ['code' => $organization->code], null, $organization->id);

        // Provision a dedicated, isolated telephony context for the organization.
        $this->provisioning->syncOrganizationEnabledState($organization);

        return redirect()->route('admin.organizations.show', $organization)
            ->with('status', __('admin.flash_created'));
    }

    public function show(Request $request, Organization $organization)
    {
        $tab = (string) $request->query('tab', 'info');
        $allowed = ['info', 'subscription', 'users', 'numbers', 'ivr', 'usage', 'recordings', 'invoices', 'activity'];
        if (! in_array($tab, $allowed, true)) {
            $tab = 'info';
        }

        $organization->load([
            'users.role',
            'subscriptions.plan',
            'invoices',
            'extensions',
            'featureSubscriptions.feature',
        ]);

        $phoneNumbers = PhoneNumber::where('organization_id', $organization->id)->get();
        $ivrMenus = IvrMenu::where('organization_id', $organization->id)->get();
        $callQueues = CallQueue::where('organization_id', $organization->id)->get();
        $recordings = Recording::where('organization_id', $organization->id)->latest()->limit(30)->get();
        $auditLogs = AuditLog::where('organization_id', $organization->id)->with('user')->latest()->limit(30)->get();
        $activationRequests = ActivationRequest::where('organization_id', $organization->id)->with('feature')->latest()->get();

        $usage = [
            'users' => $organization->users->count(),
            'extensions' => $organization->extensions->count(),
            'calls_month' => $organization->calls()->whereYear('created_at', now()->year)->whereMonth('created_at', now()->month)->count(),
            'storage' => (int) Recording::where('organization_id', $organization->id)->sum('size'),
            'numbers' => $phoneNumbers->count(),
            'customers' => $organization->customers()->count(),
            'forms' => $organization->forms()->count(),
        ];

        return view('admin.organizations.show', compact(
            'organization', 'tab', 'phoneNumbers', 'ivrMenus', 'callQueues',
            'recordings', 'auditLogs', 'activationRequests', 'usage'
        ));
    }

    public function update(OrganizationRequest $request, Organization $organization)
    {
        $data = $request->validated();
        unset($data['admin_name'], $data['admin_username'], $data['admin_email'], $data['admin_password']);

        $old = $organization->only(array_keys($data));
        $organization->update($data);

        if (in_array($organization->status, ['active', 'trial'], true) && ! $organization->activated_at) {
            $organization->update(['activated_at' => now()]);
        }

        $this->audit->log('organization.updated', $organization, $old, $organization->only(array_keys($data)), null, $organization->id);

        return redirect()->route('admin.organizations.show', $organization)->with('status', __('admin.flash_updated'));
    }

    public function updateStatus(OrganizationStatusRequest $request, Organization $organization)
    {
        $data = $request->validated();
        $old = ['status' => $organization->status];
        $organization->update([
            'status' => $data['status'],
            'activated_at' => in_array($data['status'], ['active', 'trial'], true) ? ($organization->activated_at ?? now()) : $organization->activated_at,
        ]);

        $this->audit->log('organization.status_updated', $organization, $old, ['status' => $organization->status], null, $organization->id);

        // Activation enables the whole context; suspension disables every
        // account on the telephony server, not just the Laravel UI.
        $this->provisioning->syncOrganizationEnabledState($organization);

        return back()->with('status', __('admin.flash_status'));
    }

    public function destroy(Organization $organization)
    {
        $old = ['code' => $organization->code, 'name' => $organization->name_ar];

        // Remove the telephony context before the row is soft-deleted so the
        // server stops accepting registrations for the organization.
        $this->provisioning->deprovisionOrganization($organization);

        $organization->delete();

        $this->audit->log('organization.deleted', null, $old, [], null, $organization->id);

        return redirect()->route('admin.organizations.index')->with('status', __('admin.flash_deleted'));
    }
}
