<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActivationDecisionRequest;
use App\Http\Requests\Admin\FeatureSubscriptionRequest;
use App\Models\ActivationRequest;
use App\Models\Organization;
use App\Models\OrganizationFeatureSubscription;
use App\Services\AuditService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FeatureSubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request)
    {
        $code = $this->resolveCode($request);
        $feature = $this->subscriptions->featureByCode($code);

        $subscriptions = OrganizationFeatureSubscription::query()
            ->with('organization')
            ->where('feature_id', $feature->id)
            ->when(trim((string) $request->query('q')), function ($query, $q) {
                $query->whereHas('organization', function ($org) use ($q) {
                    $org->where('name_ar', 'like', "%{$q}%")
                        ->orWhere('name_en', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%");
                });
            })
            ->when($request->query('status'), function ($query, $status) {
                if (in_array($status, ['inactive', 'active', 'suspended'], true)) {
                    $query->where('status', $status);
                }
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $activationRequests = ActivationRequest::query()
            ->with('organization')
            ->where('feature_id', $feature->id)
            ->where('status', 'pending')
            ->latest()
            ->get();

        return view('admin.features', [
            'code' => $code,
            'feature' => $feature,
            'subscriptions' => $subscriptions,
            'activationRequests' => $activationRequests,
            'organizations' => Organization::orderBy('name_ar')->get(),
            'q' => $request->query('q', ''),
            'status' => $request->query('status', ''),
        ]);
    }

    public function store(FeatureSubscriptionRequest $request, string $featureCode)
    {
        $feature = $this->subscriptions->featureByCode($featureCode);
        $data = $request->validated();

        $subscription = OrganizationFeatureSubscription::updateOrCreate(
            ['organization_id' => $data['organization_id'], 'feature_id' => $feature->id],
            $data + ['feature_id' => $feature->id]
        );

        $this->audit->log('feature_subscription.saved', $subscription, [], [
            'feature' => $feature->code,
            'status' => $subscription->status,
        ], null, $subscription->organization_id);

        return back()->with('status', __('admin.flash_saved'));
    }

    public function update(FeatureSubscriptionRequest $request, OrganizationFeatureSubscription $featureSubscription)
    {
        $data = $request->validated();
        $old = $featureSubscription->only(['status', 'starts_at', 'ends_at']);

        $featureSubscription->update($data);

        $this->audit->log('feature_subscription.updated', $featureSubscription, $old, $featureSubscription->only(array_keys($old)), null, $featureSubscription->organization_id);

        return back()->with('status', __('admin.flash_updated'));
    }

    public function destroy(OrganizationFeatureSubscription $featureSubscription)
    {
        $organizationId = $featureSubscription->organization_id;
        $featureSubscription->delete();

        $this->audit->log('feature_subscription.deleted', null, ['organization_id' => $organizationId], [], null, $organizationId);

        return back()->with('status', __('admin.flash_deleted'));
    }

    public function decide(ActivationDecisionRequest $request, ActivationRequest $activationRequest)
    {
        $data = $request->validated();

        DB::transaction(function () use ($activationRequest, $data) {
            if ($data['decision'] === 'approve') {
                $activationRequest->update([
                    'status' => 'approved',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ]);

                OrganizationFeatureSubscription::updateOrCreate(
                    [
                        'organization_id' => $activationRequest->organization_id,
                        'feature_id' => $activationRequest->feature_id,
                    ],
                    [
                        'status' => 'active',
                        'starts_at' => now(),
                    ]
                );
            } else {
                $activationRequest->update([
                    'status' => 'rejected',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ]);
            }
        });

        $this->audit->log('activation_request.decided', $activationRequest, [], [
            'decision' => $data['decision'],
            'notes' => $data['notes'] ?? null,
        ], null, $activationRequest->organization_id);

        return back()->with('status', __('admin.flash_status'));
    }

    private function resolveCode(Request $request): string
    {
        return match ($request->route()->getName()) {
            'admin.forms.index' => 'forms',
            'admin.smart-ivr.index' => 'smart_ivr',
            default => 'crm',
        };
    }
}
