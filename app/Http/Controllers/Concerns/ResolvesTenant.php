<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Record-level tenant isolation.
 *
 * The organization is ALWAYS derived from the authenticated user, never from the
 * request payload. Any bound model must belong to that organization, otherwise a
 * 404 is returned (so existence is not leaked across tenants).
 */
trait ResolvesTenant
{
    protected function organization(Request $request): Organization
    {
        $organization = $request->user()?->organization;

        abort_unless($organization instanceof Organization, 403, 'No organization context.');

        return $organization;
    }

    /**
     * Abort with 404 when the record does not belong to the current organization.
     */
    protected function authorizeTenant(Organization $organization, ?Model $model, string $column = 'organization_id'): void
    {
        abort_unless($model instanceof Model, 404);
        abort_unless((int) $model->getAttribute($column) === (int) $organization->id, 404);
    }

    /**
     * Abort when the user lacks the permission (super admins always pass).
     */
    protected function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403);
    }
}
