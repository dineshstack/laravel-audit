<?php
// src/Http/Controllers/AuditController.php

namespace Dineshstack\LaravelAudit\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Dineshstack\LaravelAudit\Services\SearchService;
use Dineshstack\LaravelAudit\Models\AuditEntry;

class AuditController extends Controller
{
    public function __construct(private readonly SearchService $search) {}

    private function auth(Request $r): void
    {
        $token = config('audit.token', '');
        if (!$token) return;
        abort_if(
            $r->header('X-Audit-Token') !== $token && $r->bearerToken() !== $token,
            401, 'Unauthorized'
        );
    }

    /** GET /api/audit/feed — paginated activity feed */
    public function feed(Request $r)
    {
        $this->auth($r);
        $result = $this->search->search(
            $r->only(['event', 'causer_id', 'subject_type', 'ip_address', 'date_from', 'date_to', 'search', 'batch_id']),
            (int) $r->query('page', 1),
            min(100, (int) $r->query('per_page', 30)),
        );
        return response()->json($result);
    }

    /** GET /api/audit/entry/{id} — single entry with full diff */
    public function show(Request $r, string $id)
    {
        $this->auth($r);
        $entry = AuditEntry::findOrFail($id);
        return response()->json($entry);
    }

    /** GET /api/audit/timeline */
    public function timeline(Request $r)
    {
        $this->auth($r);
        $result = $this->search->timeline(
            $r->query('causer_type', 'App\\Models\\User'),
            $r->query('causer_id'),
            (int) $r->query('per_page', 30),
            (int) $r->query('page', 1),
        );
        return response()->json($result);
    }

    /** GET /api/audit/stats */
    public function stats(Request $r)
    {
        $this->auth($r);
        return response()->json($this->search->stats($r->query('from'), $r->query('to')));
    }

    /**
     * GET /api/audit/causers — distinct causers for filter dropdown.
     *
     * BUG FIX: previously grouped by (causer_id, causer_name, causer_type)
     * which returned the same causer_id multiple times when a user had
     * different causer_name values across entries (e.g. after a name change).
     * This caused React duplicate key warnings in the timeline component.
     * Fix: group only by causer_id, pick the most recent name/type via MAX().
     */
    public function causers(Request $r)
    {
        $this->auth($r);
        $causers = AuditEntry::query()
            ->selectRaw('causer_id, MAX(causer_name) as causer_name, MAX(causer_type) as causer_type, count(*) as total')
            ->whereNotNull('causer_id')
            ->groupBy('causer_id')           // BUG FIX: was groupBy('causer_id', 'causer_name', 'causer_type')
            ->orderByDesc('total')
            ->limit(100)
            ->get();
        return response()->json($causers);
    }
}
