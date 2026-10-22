<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    /**
     * Record an auditable action. Secrets must never be passed here.
     *
     * When the action happened inside the "Telephony Technical Settings"
     * section the technical account identity is recorded too, so the row says
     * *which* technical sign-in performed it. A technical account is shared
     * between the support engineers of one organization, so the recorded
     * identity is the account, never the human being behind it.
     */
    public function log(
        string $action,
        ?Model $auditable = null,
        array $oldValues = [],
        array $newValues = [],
        ?User $user = null,
        ?int $organizationId = null,
        ?int $technicalAccountId = null,
    ): AuditLog {
        $user ??= auth()->user();
        $technicalAccountId ??= auth('technical')->id();

        return AuditLog::create([
            'user_id' => $user?->id,
            'technical_account_id' => $technicalAccountId,
            'organization_id' => $organizationId ?? $user?->organization_id ?? $auditable?->organization_id,
            'action' => $action,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'ip_address' => request()->ip(),
        ]);
    }
}
