<?php
// src/AuditLogger.php
// Core logger — resolves via Facade as AuditLog::log()->...->save()

namespace Dineshstack\LaravelAudit;

use Dineshstack\LaravelAudit\Services\DiffService;
use Dineshstack\LaravelAudit\Services\MaskingService;
use Dineshstack\LaravelAudit\Services\AlertService;

class AuditLogger
{
    public function __construct(
        private readonly DiffService    $diff,
        private readonly MaskingService $masking,
        private readonly AlertService   $alerts,
    ) {}

    /**
     * Start a manual audit entry.
     *
     * AuditLog::log('payment.processed')
     *     ->on($invoice)
     *     ->with(['amount' => 500])
     *     ->by($user)
     *     ->save();
     */
    public function log(string $event): PendingAuditLog
    {
        return new PendingAuditLog($event, $this->diff, $this->masking, $this->alerts);
    }

    /**
     * Wrap multiple operations in a batch — all entries share a batch_id.
     *
     * AuditLog::batch(function () use ($order) {
     *     $order->update(['status' => 'shipped']);
     *     $order->items()->each->update(['dispatched' => true]);
     * });
     */
    public function batch(callable $callback): void
    {
        $batchId = (string) \Illuminate\Support\Str::uuid();
        PendingAuditLog::setBatchId($batchId);

        try {
            $callback();
        } finally {
            PendingAuditLog::clearBatchId();
        }
    }
}
