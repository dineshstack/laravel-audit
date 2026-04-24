<?php
// src/Facades/AuditLog.php

namespace Dineshstack\LaravelAudit\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Dineshstack\LaravelAudit\PendingAuditLog log(string $event)
 * @method static void batch(callable $callback)
 *
 * @see \Dineshstack\LaravelAudit\AuditLogger
 */
class AuditLog extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'audit';
    }
}
