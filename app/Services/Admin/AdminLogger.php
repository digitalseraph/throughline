<?php

namespace App\Services\Admin;

use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminLogger
{
    public function record(User $admin, string $action, mixed $target = null, array $payload = [], ?string $ip = null): ?AdminActivityLog
    {
        [$targetType, $targetId] = $this->resolveTarget($target);

        try {
            // Use a savepoint so a DB-level failure (e.g. FK violation) does not
            // poison the outer transaction (important for Postgres in test environments).
            return DB::transaction(function () use ($admin, $action, $targetType, $targetId, $payload, $ip) {
                return AdminActivityLog::create([
                    'admin_user_id' => $admin->id,
                    'action' => $action,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'payload' => $payload,
                    'ip' => $ip,
                    'created_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            Log::warning('AdminLogger::record failed', [
                'admin_user_id' => $admin->id ?? null,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function resolveTarget(mixed $target): array
    {
        if ($target === null) {
            return [null, null];
        }
        if ($target instanceof Model) {
            return [class_basename($target), $target->getKey()];
        }
        if (is_string($target)) {
            return [$target, null];
        }

        return [null, null];
    }
}
