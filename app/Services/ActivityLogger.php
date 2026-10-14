<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes the audit trail. Example:
 *   $logger->log('role.updated', $role, ['before' => [...], 'after' => [...]]);
 * The actor defaults to whoever is signed in on the admin or client guard; none means "the system".
 */
class ActivityLogger
{
    public function __construct(private readonly Request $request) {}

    /** @param  array<string, mixed>  $properties */
    public function log(string $action, ?Model $subject = null, array $properties = [], ?Model $actor = null): ActivityLog
    {
        $actor ??= auth('admin')->user() ?? auth('client')->user();

        return ActivityLog::create([
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties ?: null,
            'ip_address' => $this->request->ip(),
        ]);
    }
}
