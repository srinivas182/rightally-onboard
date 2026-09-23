<?php

namespace App\Services\Audit;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes the audit trail. Callers pass field names that changed; values of
 * secret fields must be replaced with "[hidden]" before calling.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $changes
     */
    public function log(string $action, string $description, ?Model $subject = null, ?array $changes = null, string $actorType = 'admin'): ActivityLog
    {
        $admin = Auth::guard('admin')->user();

        return ActivityLog::create([
            'admin_id' => $actorType === 'admin' ? $admin?->getAuthIdentifier() : null,
            'actor_type' => $actorType,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'changes' => $changes,
            'ip' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 500),
        ]);
    }
}
