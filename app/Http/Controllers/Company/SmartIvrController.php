<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;

class SmartIvrController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function index(Request $request)
    {
        $org = $this->organization($request);

        // R5: Smart IVR is an independent add-on. Unless the organization's
        // subscription is active the section is hidden AND direct access is
        // refused server-side (no navigation-only protection).
        abort_unless(
            $this->subscriptions->isActive($org, 'smart_ivr'),
            403,
            __('vodnox.feature_not_active'),
        );

        return view('company.smart-ivr', compact('org'));
    }
}
