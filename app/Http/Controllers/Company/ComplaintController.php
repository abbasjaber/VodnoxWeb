<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\ComplaintRequest;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ComplaintController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request)
    {
        $organization = $this->organization($request);

        $query = Complaint::query()
            ->where('organization_id', $organization->id)
            ->with([
                'customer:id,name_ar,name_en',
                'assignee:id,name',
                'department:id,name_ar,name_en',
            ]);

        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('subject', 'like', "%{$term}%")
                    ->orWhereHas('customer', function ($customer) use ($term) {
                        $customer->where('name_ar', 'like', "%{$term}%")
                            ->orWhere('name_en', 'like', "%{$term}%");
                    });
            });
        }

        $status = (string) $request->query('status', '');
        if (in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) {
            $query->where('status', $status);
        }

        $priority = (string) $request->query('priority', '');
        if (in_array($priority, ['low', 'medium', 'high'], true)) {
            $query->where('priority', $priority);
        }

        $complaints = $query->orderByDesc('created_at')->paginate(20)->withQueryString();

        return view('company.crm.complaints', [
            'organization' => $organization,
            'complaints' => $complaints,
            'customers' => $this->customerOptions($organization),
            'users' => $this->assignableUsers($organization),
            'departments' => $this->departmentOptions($organization),
            'filters' => ['q' => $term, 'status' => $status, 'priority' => $priority],
        ]);
    }

    public function store(ComplaintRequest $request)
    {
        $organization = $this->organization($request);

        $data = $request->validated();
        $data['organization_id'] = $organization->id;

        Complaint::create($data);

        return redirect()
            ->route('company.crm.complaints.index')
            ->with('success', __('crm.messages.complaint_created'));
    }

    public function update(ComplaintRequest $request, Complaint $complaint)
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $complaint);

        $complaint->update($request->validated());

        return redirect()
            ->route('company.crm.complaints.index')
            ->with('success', __('crm.messages.complaint_updated'));
    }

    /**
     * @return Collection<int, Customer>
     */
    private function customerOptions(Organization $organization): Collection
    {
        return Customer::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name_ar')
            ->get(['id', 'name_ar', 'name_en']);
    }

    /**
     * @return Collection<int, User>
     */
    private function assignableUsers(Organization $organization): Collection
    {
        return User::query()
            ->where('organization_id', $organization->id)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @return Collection<int, Department>
     */
    private function departmentOptions(Organization $organization): Collection
    {
        return Department::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name_ar')
            ->get(['id', 'name_ar', 'name_en']);
    }
}
