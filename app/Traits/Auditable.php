<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            static::logEvent($model, 'created', null, $model->getAttributes());
        });

        static::updated(function (Model $model) {
            $oldValues = [];
            $newValues = [];

            foreach ($model->getChanges() as $key => $value) {
                // Ignore timestamp changes unless they are the only changes
                if (in_array($key, ['updated_at', 'created_at'])) {
                    continue;
                }
                $oldValues[$key] = $model->getOriginal($key);
                $newValues[$key] = $value;
            }

            if (!empty($newValues)) {
                static::logEvent($model, 'updated', $oldValues, $newValues);
            }
        });

        static::deleted(function (Model $model) {
            $event = method_exists($model, 'isForceDeleting') && $model->isForceDeleting() 
                ? 'force_deleted' 
                : 'deleted';
            static::logEvent($model, $event, $model->getAttributes(), null);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model) {
                static::logEvent($model, 'restored', null, ['restored' => true]);
            });
        }
    }

    protected static function logEvent(Model $model, string $action, ?array $oldValues, ?array $newValues): void
    {
        $userId = auth()->check() ? auth()->id() : null;
        
        // Don't log operations done by anonymous users on system logs unless registration
        if (!$userId && $action !== 'created' && $model->getTable() !== 'users') {
            // Wait, for registrations, user_id is the user being registered, but auth is not set yet
            if ($model->getTable() === 'users' && $action === 'created') {
                $userId = $model->id;
            }
        }

        AuditLog::create([
            'event' => $model->getTable() . '.' . $action,
            'user_id' => $userId ?? ($model->getTable() === 'users' && $action === 'created' ? $model->id : null),
            'auditable_type' => get_class($model),
            'auditable_id' => $model->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
