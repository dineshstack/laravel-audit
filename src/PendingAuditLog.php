<?php
// src/PendingAuditLog.php

namespace Dineshstack\LaravelAudit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;
use Dineshstack\LaravelAudit\Models\AuditEntry;
use Dineshstack\LaravelAudit\Services\DiffService;
use Dineshstack\LaravelAudit\Services\MaskingService;
use Dineshstack\LaravelAudit\Services\AlertService;

class PendingAuditLog
{
    private static ?string $currentBatchId = null;

    private ?Model  $subject      = null;
    private ?Model  $causer        = null;
    private array   $properties    = [];
    private ?array  $oldAttributes = null;
    private ?array  $newAttributes = null;
    private ?string $description   = null;

    public function __construct(
        private readonly string         $event,
        private readonly DiffService    $diff,
        private readonly MaskingService $masking,
        private readonly AlertService   $alerts,
    ) {}

    /** The model being audited */
    public function on(Model $subject): static
    {
        $this->subject = $subject;
        return $this;
    }

    /** Extra context data */
    public function with(array $properties): static
    {
        $this->properties = array_merge($this->properties, $properties);
        return $this;
    }

    /** The user who performed the action (defaults to auth()->user()) */
    public function by(?Model $causer): static
    {
        $this->causer = $causer;
        return $this;
    }

    /** Human-readable description */
    public function description(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    /** Set old/new for diff (called internally by LogsActivity trait) */
    public function diff(array $old, array $new): static
    {
        $this->oldAttributes = $old;
        $this->newAttributes = $new;
        return $this;
    }

    /** Persist the audit entry */
    public function save(): AuditEntry
    {
        $causer = $this->causer ?? auth()->user();

        // Build properties payload
        $properties = $this->properties;

        if ($this->oldAttributes !== null || $this->newAttributes !== null) {
            $properties['old'] = $this->masking->mask($this->oldAttributes ?? []);
            $properties['new'] = $this->masking->mask($this->newAttributes ?? []);
            $properties['diff'] = $this->diff->compute(
                $this->oldAttributes ?? [],
                $this->newAttributes ?? [],
            );
        }

        $entry = AuditEntry::create([
            'event'        => $this->event,
            'description'  => $this->description ?? $this->event,
            'batch_id'     => self::$currentBatchId,
            'subject_type' => $this->subject ? get_class($this->subject) : null,
            'subject_id'   => $this->subject?->getKey(),
            'causer_type'  => $causer ? get_class($causer) : null,
            'causer_id'    => $causer?->getKey(),
            'causer_name'  => $causer?->name ?? $causer?->email ?? null,
            'properties'   => $properties,
            'ip_address'   => Request::ip(),
            'user_agent'   => Request::userAgent(),
            'url'          => Request::fullUrl(),
            'method'       => Request::method(),
        ]);

        // Async suspicious-activity check
        $this->alerts->checkPattern($entry);

        return $entry;
    }

    // ── Batch helpers ─────────────────────────────────────────────────────────

    public static function setBatchId(string $id): void
    {
        self::$currentBatchId = $id;
    }

    public static function clearBatchId(): void
    {
        self::$currentBatchId = null;
    }
}
