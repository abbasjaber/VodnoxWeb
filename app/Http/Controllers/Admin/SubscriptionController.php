<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubscriptionRequest;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\AuditService;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(Request $request)
    {
        $query = Subscription::query()->with(['organization', 'plan']);

        if ($q = trim((string) $request->query('q'))) {
            $query->whereHas('organization', function ($sub) use ($q) {
                $sub->where('name_ar', 'like', "%{$q}%")
                    ->orWhere('name_en', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%");
            });
        }

        if ($status = $request->query('status')) {
            if (in_array($status, ['pending', 'active', 'trial', 'suspended', 'expired'], true)) {
                $query->where('status', $status);
            }
        }

        $subscriptions = $query->latest()->paginate(15)->withQueryString();

        return view('admin.subscriptions', [
            'subscriptions' => $subscriptions,
            'organizations' => Organization::orderBy('name_ar')->get(),
            'plans' => Plan::orderBy('sort_order')->get(),
            'q' => $request->query('q', ''),
            'status' => $request->query('status', ''),
        ]);
    }

    public function store(SubscriptionRequest $request)
    {
        $subscription = Subscription::create($request->validated());

        $this->audit->log('subscription.created', $subscription, [], $subscription->only(['organization_id', 'plan_id', 'status']), null, $subscription->organization_id);

        return back()->with('status', __('admin.flash_created'));
    }

    public function update(SubscriptionRequest $request, Subscription $subscription)
    {
        $old = $subscription->only(['plan_id', 'status', 'billing_cycle', 'starts_at', 'ends_at', 'price', 'currency']);
        $subscription->update($request->validated());

        $this->audit->log('subscription.updated', $subscription, $old, $subscription->only(array_keys($old)), null, $subscription->organization_id);

        return back()->with('status', __('admin.flash_updated'));
    }

    public function destroy(Subscription $subscription)
    {
        $organizationId = $subscription->organization_id;
        $subscription->delete();

        $this->audit->log('subscription.deleted', null, ['organization_id' => $organizationId], [], null, $organizationId);

        return back()->with('status', __('admin.flash_deleted'));
    }
}
