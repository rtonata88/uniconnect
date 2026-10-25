<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Audit log writer (SEC-9). Actions used:
 *   auth.login, auth.login_failed, auth.logout_all, user.created, user.role_changed,
 *   user.deactivated, user.activated, enrolment.added, enrolment.removed,
 *   grade.changed, grade.released, submission.offline
 */
class Audit
{
    public static function log(string $action, ?Model $subject = null, array $meta = [], ?int $userId = null): AuditLog
    {
        $request = request();

        return AuditLog::create([
            'user_id' => $userId ?? $request?->user()?->id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'ip' => $request?->ip(),
            'user_agent' => Str::limit((string) $request?->userAgent(), 500, ''),
            'meta' => $meta ?: null,
        ]);
    }
}
