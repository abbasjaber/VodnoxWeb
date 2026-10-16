<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\TeamMemberRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Telephony\TelephonyProvisioningService;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private readonly AuditService $audit,
        private readonly TelephonyProvisioningService $provisioning,
    ) {}

    public function index(Request $request)
    {
        $org = $this->organization($request);

        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $members = $org->users()
            ->with('role')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%{$q}%")
                        ->orWhere('username', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                });
            })
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('active', $status === 'active'))
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $roles = Role::query()
            ->whereIn('name', ['organization_admin', 'supervisor', 'agent', 'viewer'])
            ->orderBy('id')
            ->get();

        return view('company.team', compact('org', 'members', 'roles', 'q', 'status'));
    }

    public function store(TeamMemberRequest $request)
    {
        $org = $this->organization($request);
        $data = $request->validated();

        $user = User::create([
            'organization_id' => $org->id,
            'role_id' => $data['role_id'],
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
            'active' => $request->boolean('active'),
            'position' => $data['position'] ?? null,
            'phone' => $data['phone'] ?? null,
            'extension' => $data['extension'] ?? null,
        ]);

        $this->audit->log(
            'team.member.created',
            $user,
            [],
            ['username' => $user->username, 'role_id' => $user->role_id, 'active' => $user->active],
            null,
            $org->id,
        );

        $this->syncMemberTelephony($user, $data['extension'] ?? null);

        return redirect()->route('company.team.index')->with('status', __('company.created'));
    }

    public function update(TeamMemberRequest $request, User $member)
    {
        $org = $this->organization($request);
        $this->authorizeTenant($org, $member);

        $data = $request->validated();
        $old = $member->only(['name', 'username', 'email', 'role_id', 'active']);

        $member->fill([
            'role_id' => $data['role_id'],
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'active' => $request->boolean('active'),
            'position' => $data['position'] ?? null,
            'phone' => $data['phone'] ?? null,
            'extension' => $data['extension'] ?? null,
        ]);

        if (! empty($data['password'])) {
            $member->password = $data['password'];
        }

        $member->save();

        $this->audit->log(
            'team.member.updated',
            $member,
            $old,
            $member->only(['name', 'username', 'email', 'role_id', 'active']),
            null,
            $org->id,
        );

        $this->syncMemberTelephony($member->refresh(), $data['extension'] ?? null);

        return redirect()->route('company.team.index')->with('status', __('company.updated'));
    }

    public function destroy(Request $request, User $member)
    {
        $org = $this->organization($request);
        $this->authorizeTenant($org, $member);

        abort_if($member->getKey() === $request->user()->getKey(), 403, __('company.cannot_delete_self'));

        // Withdraw the SIP account first so the server stops accepting it.
        $member->extensions()
            ->get()
            ->each(fn ($extension) => $this->provisioning->deprovisionExtension($extension));

        $this->audit->log(
            'team.member.deleted',
            $member,
            ['username' => $member->username, 'name' => $member->name],
            [],
            null,
            $org->id,
        );

        $member->delete();

        return redirect()->route('company.team.index')->with('status', __('company.deleted'));
    }

    /**
     * Keep the employee's telephony asset in sync with their account state.
     */
    private function syncMemberTelephony(User $member, ?string $number): void
    {
        if (! $this->provisioning->isEligible($member)) {
            return;
        }

        if (! $member->active) {
            $member->extensions()
                ->get()
                ->each(fn ($extension) => $this->provisioning->disableExtension($extension));

            return;
        }

        $extension = $this->provisioning->ensureExtensionForUser($member, $number);

        if ($extension) {
            $this->provisioning->syncExtension($extension);
        }
    }
}
