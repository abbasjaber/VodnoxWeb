<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\Interaction;
use App\Models\Opportunity;
use App\Models\Task;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CrmController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * CRM landing page: activation request when inactive, overview dashboard when active.
     */
    public function index(Request $request)
    {
        $organization = $this->organization($request);

        if (! $this->subscriptions->isActive($organization, 'crm')) {
            return view('company.crm.index', [
                'active' => false,
                'organization' => $organization,
                'subscription' => $this->subscriptions->featureSubscription($organization, 'crm'),
            ]);
        }

        $organizationId = $organization->id;

        $metrics = [
            'customers' => Customer::query()->where('organization_id', $organizationId)->count(),
            'open_opportunities' => Opportunity::query()
                ->where('organization_id', $organizationId)
                ->where('status', 'open')
                ->count(),
            'open_opportunities_value' => (float) Opportunity::query()
                ->where('organization_id', $organizationId)
                ->where('status', 'open')
                ->sum('value'),
            'open_tasks' => Task::query()
                ->where('organization_id', $organizationId)
                ->where('status', 'pending')
                ->count(),
            'open_complaints' => Complaint::query()
                ->where('organization_id', $organizationId)
                ->whereIn('status', ['open', 'in_progress'])
                ->count(),
        ];

        $recentInteractions = Interaction::query()
            ->with(['customer:id,name_ar,name_en', 'user:id,name'])
            ->where('organization_id', $organizationId)
            ->orderByDesc('occurred_at')
            ->limit(8)
            ->get();

        return view('company.crm.index', [
            'active' => true,
            'organization' => $organization,
            'subscription' => $this->subscriptions->featureSubscription($organization, 'crm'),
            'metrics' => $metrics,
            'recentInteractions' => $recentInteractions,
        ]);
    }

    /**
     * Per-organization CRM metrics (query-builder aggregation only).
     */
    public function reports(Request $request)
    {
        $organization = $this->organization($request);
        $organizationId = $organization->id;

        $customersByStatus = Customer::query()
            ->where('organization_id', $organizationId)
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $opportunitiesByStage = DB::table('opportunities')
            ->leftJoin('opportunity_stages', 'opportunity_stages.id', '=', 'opportunities.stage_id')
            ->where('opportunities.organization_id', $organizationId)
            ->select(
                'opportunity_stages.id as stage_id',
                'opportunity_stages.name_ar',
                'opportunity_stages.name_en',
                'opportunity_stages.order',
                DB::raw('count(opportunities.id) as total'),
                DB::raw('coalesce(sum(opportunities.value), 0) as value')
            )
            ->groupBy('opportunity_stages.id', 'opportunity_stages.name_ar', 'opportunity_stages.name_en', 'opportunity_stages.order')
            ->orderBy('opportunity_stages.order')
            ->get();

        $tasksByStatus = Task::query()
            ->where('organization_id', $organizationId)
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $overdueTasks = Task::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'pending')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();

        $complaintsByStatus = Complaint::query()
            ->where('organization_id', $organizationId)
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $complaintsByPriority = Complaint::query()
            ->where('organization_id', $organizationId)
            ->select('priority', DB::raw('count(*) as aggregate'))
            ->groupBy('priority')
            ->pluck('aggregate', 'priority');

        $interactionsDaily = DB::table('interactions')
            ->where('organization_id', $organizationId)
            ->where('occurred_at', '>=', now()->subDays(29)->startOfDay())
            ->select(DB::raw('date(occurred_at) as day'), DB::raw('count(*) as aggregate'))
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('aggregate', 'day');

        return view('company.crm.reports', [
            'organization' => $organization,
            'customersByStatus' => $customersByStatus->all(),
            'customersTotal' => (int) $customersByStatus->sum(),
            'opportunitiesByStage' => $opportunitiesByStage,
            'opportunitiesTotal' => (int) $opportunitiesByStage->sum('total'),
            'opportunitiesValueTotal' => (float) $opportunitiesByStage->sum('value'),
            'tasksByStatus' => $tasksByStatus->all(),
            'tasksTotal' => (int) $tasksByStatus->sum(),
            'overdueTasks' => $overdueTasks,
            'complaintsByStatus' => $complaintsByStatus->all(),
            'complaintsTotal' => (int) $complaintsByStatus->sum(),
            'complaintsByPriority' => $complaintsByPriority->all(),
            'interactionsDaily' => $interactionsDaily->all(),
            'interactionsTotal' => (int) $interactionsDaily->sum(),
        ]);
    }
}
