<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TeamRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(Request $request)
    {
        $query = User::query()->with('role')
            ->where(function ($sub) {
                $sub->whereNull('organization_id')
                    ->orWhereHas('role', fn ($role) => $role->where('name', 'super_admin'));
            });

        if ($q = trim((string) $request->query('q'))) {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('username', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        $members = $query->latest()->paginate(15)->withQueryString();

        return view('admin.team', [
            'members' => $members,
            'q' => $request->query('q', ''),
        ]);
    }

    public function store(TeamRequest $request)
    {
        $data = $request->validated();
        $role = Role::where('name', 'super_admin')->first();

        $user = User::create([
            'organization_id' => null,
            'role_id' => $role?->id,
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'position' => $data['position'] ?? null,
            'password' => $data['password'],
            'active' => true,
        ]);

        $this->audit->log('team.member_created', $user, [], ['username' => $user->username], null, null);

        return back()->with('status', __('admin.flash_created'));
    }

    public function update(TeamRequest $request, User $user)
    {
        $data = $request->validated();

        $payload = [
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'position' => $data['position'] ?? null,
        ];

        if (! empty($data['password'])) {
            $payload['password'] = $data['password'];
        }

        $old = $user->only(['name', 'username', 'email', 'phone', 'position']);
        $user->update($payload);

        $this->audit->log('team.member_updated', $user, $old, $user->only(array_keys($old)), null, null);

        return back()->with('status', __('admin.flash_updated'));
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return back()->withErrors(['team' => __('admin.team_cannot_delete_self')]);
        }

        $user->delete();

        $this->audit->log('team.member_deleted', null, ['username' => $user->username], [], null, null);

        return back()->with('status', __('admin.flash_deleted'));
    }
}
