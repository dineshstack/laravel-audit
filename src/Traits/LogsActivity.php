<?php
// src/Traits/LogsActivity.php
// Add this trait to any Eloquent model to auto-log create/update/delete

namespace Dineshstack\LaravelAudit\Traits;

use Dineshstack\LaravelAudit\PendingAuditLog;

trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        // ── Create ───────────────────────────────────────────────────────────
        static::created(function (self $model) {
            static::auditLog('create')
                ->on($model)
                ->diff([], $model->getAttributes())
                ->description('Created '.class_basename($model))
                ->save();
        });

        // ── Update ───────────────────────────────────────────────────────────
        static::updated(function (self $model) {
            $dirty = $model->getDirty();
            if (empty($dirty)) return;

            $old = array_intersect_key($model->getRawOriginal(), $dirty);
            $new = array_intersect_key($model->getAttributes(), $dirty);

            static::auditLog('update')
                ->on($model)
                ->diff($old, $new)
                ->description('Updated '.class_basename($model).' #'.$model->getKey())
                ->save();
        });

        // ── Delete ───────────────────────────────────────────────────────────
        static::deleted(function (self $model) {
            static::auditLog('delete')
                ->on($model)
                ->diff($model->getAttributes(), [])
                ->description('Deleted '.class_basename($model).' #'.$model->getKey())
                ->save();
        });
    }

    /** Override in your model to exclude columns from audit logs */
    protected function getAuditExclude(): array
    {
        return ['updated_at', 'created_at', 'remember_token'];
    }

    /** Override to include only specific columns */
    protected function getAuditInclude(): array
    {
        return []; // empty = include all (minus excluded)
    }

    private static function auditLog(string $event): PendingAuditLog
    {
        return app('audit')->log($event);
    }
}
