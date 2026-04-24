<?php
// src/Models/AuditEntry.php

namespace Dineshstack\LaravelAudit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class AuditEntry extends Model
{
    // ── ULID primary key — NOT auto-increment ────────────────────────────────
    public    $incrementing = false;   // BUG FIX: was missing — caused Eloquent to treat id as integer
    protected $keyType      = 'string'; // BUG FIX: was missing — caused findOrFail to cast to int
    public    $timestamps   = false;

    protected $table = 'audit_logs';

    protected $fillable = [
        'id',                          // BUG FIX: was missing — ULID must be mass-assignable
        'event', 'description', 'batch_id',
        'subject_type', 'subject_id',
        'causer_type',  'causer_id', 'causer_name',
        'properties',
        'ip_address', 'user_agent', 'url', 'method',
    ];

    protected $casts = [
        'properties' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            // BUG FIX: was missing — ULID must be generated before insert
            if (empty($model->id)) {
                $model->id = (string) Str::ulid();
            }
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeEvent(Builder $q, string $event): Builder
    {
        return $q->where('event', $event);
    }

    public function scopeCausedBy(Builder $q, Model $causer): Builder
    {
        return $q->where('causer_type', get_class($causer))
                 ->where('causer_id', $causer->getKey());
    }

    public function scopeForSubject(Builder $q, Model $subject): Builder
    {
        return $q->where('subject_type', get_class($subject))
                 ->where('subject_id', $subject->getKey());
    }

    public function scopeInBatch(Builder $q, string $batchId): Builder
    {
        return $q->where('batch_id', $batchId);
    }

    public function scopeInDateRange(Builder $q, ?string $from, ?string $to): Builder
    {
        if ($from) $q->where('created_at', '>=', $from);
        if ($to)   $q->where('created_at', '<=', $to);
        return $q;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function hasDiff(): bool
    {
        return !empty($this->properties['diff']);
    }

    public function getOldAttribute(): array
    {
        return $this->properties['old'] ?? [];
    }

    public function getNewAttribute(): array
    {
        return $this->properties['new'] ?? [];
    }

    public function getDiffAttribute(): array
    {
        return $this->properties['diff'] ?? [];
    }
}
