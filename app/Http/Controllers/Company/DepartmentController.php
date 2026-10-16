<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\DepartmentRequest;
use App\Models\Department;
use App\Services\AuditService;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request)
    {
        $org = $this->organization($request);

        $q = trim((string) $request->query('q', ''));

        $departments = $org->departments()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('name_ar', 'like', "%{$q}%")
                        ->orWhere('name_en', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%");
                });
            })
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('company.departments', compact('org', 'departments', 'q'));
    }

    public function store(DepartmentRequest $request)
    {
        $org = $this->organization($request);
        $data = $request->validated();

        $department = $org->departments()->create([
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'code' => $data['code'] ?? null,
        ]);

        $this->audit->log('department.created', $department, [], $department->only(['name_ar', 'name_en', 'code']), null, $org->id);

        return redirect()->route('company.departments.index')->with('status', __('company.created'));
    }

    public function update(DepartmentRequest $request, Department $department)
    {
        $org = $this->organization($request);
        $this->authorizeTenant($org, $department);

        $data = $request->validated();
        $old = $department->only(['name_ar', 'name_en', 'code']);

        $department->fill([
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'code' => $data['code'] ?? null,
        ])->save();

        $this->audit->log('department.updated', $department, $old, $department->only(['name_ar', 'name_en', 'code']), null, $org->id);

        return redirect()->route('company.departments.index')->with('status', __('company.updated'));
    }

    public function destroy(Request $request, Department $department)
    {
        $org = $this->organization($request);
        $this->authorizeTenant($org, $department);

        $this->audit->log('department.deleted', $department, ['name_ar' => $department->name_ar, 'name_en' => $department->name_en], [], null, $org->id);

        $department->delete();

        return redirect()->route('company.departments.index')->with('status', __('company.deleted'));
    }
}
