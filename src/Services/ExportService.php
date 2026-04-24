<?php
// src/Services/ExportService.php
// Phase 2: CSV and PDF export for compliance reporting

namespace Dineshstack\LaravelAudit\Services;

use League\Csv\Writer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Dineshstack\LaravelAudit\Models\AuditEntry;
use Illuminate\Database\Eloquent\Builder;

class ExportService
{
    /** Export filtered audit logs as CSV, returns string content */
    public function toCsv(Builder $query, int $limit = 10000): string
    {
        $csv = Writer::createFromString();

        $csv->insertOne([
            'ID', 'Event', 'Description', 'Causer', 'Subject',
            'IP Address', 'URL', 'Method', 'Batch ID', 'Date/Time',
        ]);

        $query->limit($limit)->chunk(500, function ($rows) use ($csv) {
            foreach ($rows as $entry) {
                $csv->insertOne([
                    $entry->id,
                    $entry->event,
                    $entry->description,
                    $entry->causer_name ?? "{$entry->causer_type}:{$entry->causer_id}",
                    $entry->subject_type ? "{$entry->subject_type}:{$entry->subject_id}" : '—',
                    $entry->ip_address,
                    $entry->url,
                    $entry->method,
                    $entry->batch_id ?? '—',
                    $entry->created_at?->toDateTimeString(),
                ]);
            }
        });

        return $csv->toString();
    }

    /** Export filtered audit logs as PDF, returns binary string */
    public function toPdf(Builder $query, array $meta = []): string
    {
        $rows = $query->limit(1000)->get();

        $html = $this->buildPdfHtml($rows, $meta);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }

    private function buildPdfHtml($rows, array $meta): string
    {
        $title     = $meta['title']     ?? 'Audit Trail Report';
        $generated = now()->toDateTimeString();
        $count     = $rows->count();

        $rowsHtml = $rows->map(fn($e) => "
            <tr>
                <td>{$this->e($e->event)}</td>
                <td>{$this->e($e->description)}</td>
                <td>{$this->e($e->causer_name ?? $e->causer_id)}</td>
                <td>{$this->e($e->subject_type ? class_basename($e->subject_type).':'.$e->subject_id : '—')}</td>
                <td>{$this->e($e->ip_address)}</td>
                <td>{$this->e($e->created_at?->toDateTimeString())}</td>
            </tr>
        ")->implode('');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1a1a1a; }
  h1 { font-size: 14px; margin-bottom: 4px; }
  .meta { color: #666; font-size: 8px; margin-bottom: 12px; }
  table { width: 100%; border-collapse: collapse; }
  th { background: #4f46e5; color: #fff; padding: 5px 6px; text-align: left; font-size: 8px; }
  td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
  tr:nth-child(even) td { background: #f9fafb; }
  .badge { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 7px; font-weight: bold; }
  .ev-create { background: #d1fae5; color: #065f46; }
  .ev-update { background: #dbeafe; color: #1e40af; }
  .ev-delete { background: #fee2e2; color: #991b1b; }
</style>
</head>
<body>
<h1>{$title}</h1>
<div class="meta">Generated: {$generated} &nbsp;|&nbsp; Total records: {$count}</div>
<table>
<thead>
  <tr>
    <th>Event</th><th>Description</th><th>Causer</th>
    <th>Subject</th><th>IP Address</th><th>Date / Time</th>
  </tr>
</thead>
<tbody>{$rowsHtml}</tbody>
</table>
</body>
</html>
HTML;
    }

    private function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
