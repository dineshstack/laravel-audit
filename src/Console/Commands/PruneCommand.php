<?php
// src/Console/Commands/PruneCommand.php

namespace Dineshstack\LaravelAudit\Console\Commands;

use Illuminate\Console\Command;
use Dineshstack\LaravelAudit\Models\AuditEntry;

class PruneCommand extends Command
{
    protected $signature   = 'audit:prune {--days=90 : Delete logs older than this many days}';
    protected $description = 'Delete audit logs older than the configured retention period';

    public function handle(): int
    {
        $days    = (int) $this->option('days');
        $cutoff  = now()->subDays($days);
        $deleted = AuditEntry::query()->where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} audit log entries older than {$days} days.");
        return self::SUCCESS;
    }
}
