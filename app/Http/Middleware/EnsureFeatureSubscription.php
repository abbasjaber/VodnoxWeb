<?php

namespace App\Http\Middleware;

use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard server-side gate for independently-subscribed features (crm, forms, smart_ivr).
 * Hiding navigation is not enough: every data route passes through this middleware.
 */
class EnsureFeatureSubscription
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $organization = $user->organization;

        if ($this->subscriptions->isActive($organization, $feature)) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            abort(403, __('vodnox.feature_not_active'));
        }

        $landing = match ($feature) {
            'crm' => 'company.crm.index',
            'forms' => 'company.forms.index',
            'smart_ivr' => 'company.smart-ivr.index',
            default => 'company.index',
        };

        return redirect()->route($landing)->with('error', __('vodnox.feature_not_active'));
    }
}
