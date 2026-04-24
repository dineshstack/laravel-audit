<?php
// src/Http/Controllers/ExportController.php

namespace Dineshstack\LaravelAudit\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Dineshstack\LaravelAudit\Services\ExportService;
use Dineshstack\LaravelAudit\Services\SearchService;
use Dineshstack\LaravelAudit\Services\AlertService;

class ExportController extends Controller
{
    public function __construct(
        private readonly ExportService  $export,
        private readonly SearchService  $search,
        private readonly AlertService   $alerts,
    ) {}

    private function auth(Request $r): void
    {
        $token = config('audit.token', '');
        if (!$token) return;
        abort_if($r->header('X-Audit-Token') !== $token && $r->bearerToken() !== $token, 401);
    }

    /** GET /api/audit/export/csv */
    public function csv(Request $r): StreamedResponse
    {
        $this->auth($r);

        // Track export for rate-alert
        $this->alerts->checkPattern(new \Dineshstack\LaravelAudit\Models\AuditEntry([
            'event'       => 'export.csv',
            'causer_id'   => auth()->id(),
            'causer_name' => auth()->user()?->name,
            'ip_address'  => $r->ip(),
        ]));

        $query  = (new \Dineshstack\LaravelAudit\Services\SearchService)->search($r->only([
            'event','causer_id','subject_type','ip_address','date_from','date_to','search',
        ]), 1, 10000);

        $query2 = \Dineshstack\LaravelAudit\Models\AuditEntry::query();
        foreach ($r->only(['event','causer_id','subject_type','ip_address']) as $k => $v) {
            if ($v) $query2->where($k, $v);
        }
        if ($r->date_from) $query2->where('created_at', '>=', $r->date_from);
        if ($r->date_to)   $query2->where('created_at', '<=', $r->date_to);

        $csv = $this->export->toCsv($query2);
        $filename = 'audit-export-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(fn() => print($csv), $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /** GET /api/audit/export/pdf */
    public function pdf(Request $r): \Illuminate\Http\Response
    {
        $this->auth($r);

        $query = \Dineshstack\LaravelAudit\Models\AuditEntry::query()->orderByDesc('created_at');
        foreach ($r->only(['event','causer_id','subject_type','ip_address']) as $k => $v) {
            if ($v) $query->where($k, $v);
        }
        if ($r->date_from) $query->where('created_at', '>=', $r->date_from);
        if ($r->date_to)   $query->where('created_at', '<=', $r->date_to);

        $pdf      = $this->export->toPdf($query, ['title' => 'Audit Trail Report']);
        $filename = 'audit-report-'.now()->format('Y-m-d-His').'.pdf';

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
