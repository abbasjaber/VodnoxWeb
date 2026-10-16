<?php

namespace App\Services;

use App\Models\Feature;
use App\Models\Organization;
use App\Models\OrganizationFeatureSubscription;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    /**
     * Resolve the active feature subscription for an organization, or null.
     */
    public function featureSubscription(Organization $organization, string $featureCode): ?OrganizationFeatureSubscription
    {
        return $organization->featureSubscriptions()
            ->whereHas('feature', fn ($q) => $q->where('code', $featureCode))
            ->first();
    }

    /**
     * Whether the feature is active (status + period) for the organization.
     */
    public function isActive(Organization $organization, string $featureCode): bool
    {
        $subscription = $this->featureSubscription($organization, $featureCode);

        return $subscription?->isActive() ?? false;
    }

    /**
     * Hard server-side gate: throws 403 when the feature is not active.
     */
    public function requireActive(?Organization $organization, string $featureCode): void
    {
        if (! $organization || ! $this->isActive($organization, $featureCode)) {
            abort(403, __('vodnox.feature_not_active'));
        }
    }

    public function featureByCode(string $code): Feature
    {
        return Feature::firstOrCreate(
            ['code' => $code],
            ['name_ar' => $code, 'name_en' => strtoupper($code), 'is_active' => true]
        );
    }

    /**
     * Count usage against a limit key for a given feature.
     */
    public function usage(Organization $organization, string $featureCode, string $limitKey): int
    {
        return match ($featureCode.':'.$limitKey) {
            'crm:customers' => $organization->customers()->count(),
            'crm:users' => $this->featureUsersCount($organization),
            'forms:forms' => $organization->forms()->count(),
            'forms:submissions' => (int) DB::table('form_submissions')
                ->join('forms', 'forms.id', '=', 'form_submissions.form_id')
                ->where('forms.organization_id', $organization->id)
                ->count(),
            default => 0,
        };
    }

    /**
     * Whether a new record of the given limit key may be created.
     */
    public function withinLimit(Organization $organization, string $featureCode, string $limitKey, int $adding = 1): bool
    {
        $subscription = $this->featureSubscription($organization, $featureCode);
        if (! $subscription) {
            return false;
        }

        $max = match ($limitKey) {
            'users' => $subscription->max_users,
            'customers' => $subscription->max_customers,
            'forms' => $subscription->max_forms,
            'submissions' => $subscription->max_submissions,
            default => null,
        };

        if ($max === null) {
            return true;
        }

        return $this->usage($organization, $featureCode, $limitKey) + $adding <= $max;
    }

    private function featureUsersCount(Organization $organization): int
    {
        return $organization->users()->count();
    }
}
