<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\TaskRequest;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TaskController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request)
    {
        $organization = $this->organization($request);

        $query = Task::query()
            ->where('organization_id', $organization->id)
            ->with(['customer:id,name_ar,name_en', 'assignedTo:id,name']);

        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhereHas('customer', function ($customer) use ($term) {
                        $customer->where('name_ar', 'like', "%{$term}%")
                            ->orWhere('name_en', 'like', "%{$term}%");
                    });
            });
        }

        $status = (string) $request->query('status', '');
        if (in_array($status, ['pending', 'completed', 'cancelled'], true)) {
            $query->where('status', $status);
        }

        $priority = (string) $request->query('priority', '');
        if (in_array($priority, ['low', 'medium', 'high'], true)) {
            $query->where('priority', $priority);
        }

        $tasks = $query->orderByDesc('created_at')->paginate(20)->withQueryString();

        return view('company.crm.tasks', [
            'organization' => $organization,
            'tasks' => $tasks,
            'customers' => $this->customerOptions($organization),
            'users' => $this->assignableUsers($organization),
            'filters' => ['q' => $term, 'status' => $status, 'priority' => $priority],
        ]);
    }

    public function store(TaskRequest $request)
    {
        $organization = $this->organization($request);

        $data = $request->validated();
        $data['organization_id'] = $organization->id;

        Task::create($data);

        return redirect()
            ->route('company.crm.tasks.index')
            ->with('success', __('crm.messages.task_created'));
    }

    public function update(TaskRequest $request, Task $task)
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $task);

        $task->update($request->validated());

        return redirect()
            ->route('company.crm.tasks.index')
            ->with('success', __('crm.messages.task_updated'));
    }

    public function destroy(Request $request, Task $task)
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $task);

        $task->delete();

        return redirect()
            ->route('company.crm.tasks.index')
            ->with('success', __('crm.messages.task_deleted'));
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
}
