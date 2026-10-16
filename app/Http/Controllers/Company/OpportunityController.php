<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\OpportunityRequest;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\OpportunityStage;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class OpportunityController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request)
    {
        $organization = $this->organization($request);
        $stages = $this->stages($organization);

        $query = Opportunity::query()
            ->where('organization_id', $organization->id)
            ->with(['customer:id,name_ar,name_en', 'stage:id,name_ar,name_en', 'assignedTo:id,name']);

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

        $stage = $request->query('stage');
        if ($stage !== null && $stage !== '' && $stage !== 'all') {
            $query->where('stage_id', (int) $stage);
        }

        $status = (string) $request->query('status', '');
        if (in_array($status, ['open', 'won', 'lost'], true)) {
            $query->where('status', $status);
        }

        $opportunities = $query->orderByDesc('created_at')->paginate(20)->withQueryString();

        return view('company.crm.opportunities', [
            'organization' => $organization,
            'opportunities' => $opportunities,
            'stages' => $stages,
            'customers' => $this->customerOptions($organization),
            'users' => $this->assignableUsers($organization),
            'filters' => ['q' => $term, 'stage' => $stage, 'status' => $status],
            'defaultStageId' => $stages->first()?->id,
        ]);
    }

    public function store(OpportunityRequest $request)
    {
        $organization = $this->organization($request);

        $data = $request->validated();
        $data['organization_id'] = $organization->id;
        $data['stage_id'] = $data['stage_id'] ?? $this->stages($organization)->first()?->id;

        Opportunity::create($data);

        return redirect()
            ->route('company.crm.opportunities.index')
            ->with('success', __('crm.messages.opportunity_created'));
    }

    public function update(OpportunityRequest $request, Opportunity $opportunity)
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $opportunity);

        $opportunity->update($request->validated());

        return redirect()
            ->route('company.crm.opportunities.index')
            ->with('success', __('crm.messages.opportunity_updated'));
    }

    public function destroy(Request $request, Opportunity $opportunity)
    {
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $opportunity);

        $opportunity->delete();

        return redirect()
            ->route('company.crm.opportunities.index')
            ->with('success', __('crm.messages.opportunity_deleted'));
    }

    /**
     * Default opportunity stages, seeded on first access for the organization.
     *
     * @return Collection<int, OpportunityStage>
     */
    private function stages(Organization $organization): Collection
    {
        $stages = OpportunityStage::query()
            ->where('organization_id', $organization->id)
            ->orderBy('order')
            ->get();

        if ($stages->isNotEmpty()) {
            return $stages;
        }

        $defaults = [
            ['name_ar' => 'جديدة', 'name_en' => 'New'],
            ['name_ar' => 'مؤهلة', 'name_en' => 'Qualified'],
            ['name_ar' => 'عرض سعر', 'name_en' => 'Proposal'],
            ['name_ar' => 'ناجحة', 'name_en' => 'Won'],
            ['name_ar' => 'خاسرة', 'name_en' => 'Lost'],
        ];

        foreach ($defaults as $order => $stage) {
            OpportunityStage::firstOrCreate(
                ['organization_id' => $organization->id, 'name_en' => $stage['name_en']],
                ['name_ar' => $stage['name_ar'], 'order' => $order]
            );
        }

        return OpportunityStage::query()
            ->where('organization_id', $organization->id)
            ->orderBy('order')
            ->get();
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
