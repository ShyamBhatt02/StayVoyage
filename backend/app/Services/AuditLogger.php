<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class AuditLogger
{
    /** Attributes that must never be written to the audit trail. */
    private const REDACT = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    public function log(
        string $action,
        ?Model $entity = null,
        ?array $old = null,
        ?array $new = null,
        ?string $description = null,
        ?int $userId = null,
    ): AuditLog {
        $request = request();

        $log = AuditLog::query()->create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'entity_type' => $entity ? class_basename($entity) : null,
            'entity_id' => $entity?->getKey(),
            'old_values' => $old ? $this->clean($old) : null,
            'new_values' => $new ? $this->clean($new) : null,
            'description' => $description,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
        ]);

        Log::channel('admin')->info($action, [
            'user_id' => $log->user_id,
            'entity' => $log->entity_type,
            'entity_id' => $log->entity_id,
            'description' => $description,
        ]);

        return $log;
    }

    /** Log only the attributes that actually changed on a model that was just saved. */
    public function logChanges(string $action, Model $model, array $original, ?string $description = null): ?AuditLog
    {
        $changes = $model->getChanges();
        unset($changes['updated_at']);
        if (! $changes) {
            return null;
        }
        $old = array_intersect_key($original, $changes);

        return $this->log($action, $model, $old, $changes, $description);
    }

    private function clean(array $values): array
    {
        foreach (self::REDACT as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }
}
