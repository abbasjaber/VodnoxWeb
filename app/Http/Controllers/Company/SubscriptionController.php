<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\ActivationRequestRequest;
use App\Models\ActivationRequest;
use App\Models\Invoice;
use App\Services\AuditService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private readonly AuditService $audit,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function index(Request $request)
    {
        $org = $this->organization($request);

        $subscription = $org->subscriptions()->with('plan')->latest('id')->first();

        $featureSubscriptions = $org->featureSubscriptions()->with('feature')->get();

        $invoices = Invoice::query()
            ->where('organization_id', $org->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $pendingRequests = ActivationRequest::query()
            ->where('organization_id', $org->id)
            ->where('status', 'pending')
            ->with('feature')
            ->get();

        $features = ['crm', 'forms', 'smart_ivr'];

        return view('company.subscription', compact('org', 'subscription', 'featureSubscriptions', 'invoices', 'pendingRequests', 'features'));
    }

    public function requestActivation(ActivationRequestRequest $request)
    {
        $org = $this->organization($request);
        $data = $request->validated();

        $feature = $this->subscriptions->featureByCode($data['feature']);

        $exists = ActivationRequest::query()
            ->where('organization_id', $org->id)
            ->where('feature_id', $feature->id)
            ->where('status', 'pending')
            ->exists();

        if ($exists) {
            return redirect()->route('company.subscription.index')->with('error', __('company.request_pending_exists'));
        }

        $activation = ActivationRequest::create([
            'organization_id' => $org->id,
            'feature_id' => $feature->id,
            'status' => 'pending',
            'notes' => $data['notes'] ?? null,
        ]);

        $this->audit->log('subscription.activation_requested', $activation, [], ['feature' => $feature->code], null, $org->id);

        return redirect()->route('company.subscription.index')->with('status', __('company.request_sent'));
    }
}
