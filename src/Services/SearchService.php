<?php
// src/Services/SearchService.php
// Phase 3: Full-text search with Redis query caching

namespace Dineshstack\LaravelAudit\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Dineshstack\LaravelAudit\Models\AuditEntry;
use Illuminate\Pagination\LengthAwarePaginator;

class SearchService
{
    /**
     * Filtered, paginated audit log search.
     *
     * BUG FIX: Removed Cache::remember() wrapper around paginator.
     * LengthAwarePaginator stores Eloquent models which can fail to
     * serialize when using file/redis cache drivers, causing a 500.
     * Search results are user-specific and change frequently so caching
     * adds little value. Stats (slower aggregate query) are still cached.
     */
    public function search(array $filters = [], int $page = 1, int $perPage = 30): LengthAwarePaginator
    {
        $q = AuditEntry::query()->orderByDesc('created_at');

        if (!empty($filters['event'])) {
            $q->where('event', $filters['event']);
        }

        if (!empty($filters['causer_id'])) {
            $q->where('causer_id', $filters['causer_id']);
        }

        if (!empty($filters['subject_type'])) {
            $q->where('subject_type', 'like', '%'.$filters['subject_type'].'%');
        }

        if (!empty($filters['ip_address'])) {
            $q->where('ip_address', $filters['ip_address']);
        }

        if (!empty($filters['date_from'])) {
            $q->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $q->where('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($q2) use ($term) {
                $q2->where('description', 'like', "%{$term}%")
                   ->orWhere('subject_type', 'like', "%{$term}%")
                   ->orWhere('causer_name', 'like', "%{$term}%")
                   ->orWhere('url', 'like', "%{$term}%")
                   ->orWhere('ip_address', 'like', "%{$term}%");
            });
        }

        if (!empty($filters['batch_id'])) {
            $q->where('batch_id', $filters['batch_id']);
        }

        return $q->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Full activity timeline for a single causer (user).
     */
    public function timeline(string $causerType, int|string $causerId, int $perPage = 30, int $page = 1): LengthAwarePaginator
    {
        return AuditEntry::query()
            ->where('causer_type', $causerType)
            ->where('causer_id', $causerId)
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Statistics for the stats dashboard page.
     *
     * BUG FIX: Cache stores a plain PHP array of primitives now.
     * Previously cached Eloquent Collection objects which can fail
     * to unserialize. Now converts all results to plain arrays before caching.
     */
    public function stats(?string $from = null, ?string $to = null): array
    {
        $cacheKey = 'audit:stats:'.md5("{$from}:{$to}");

        return Cache::remember($cacheKey, 120, function () use ($from, $to) {
            $q = AuditEntry::query()->inDateRange($from, $to);

            // BUG FIX: convert all Eloquent collections to plain arrays before caching
            return [
                'total'        => $q->count(),
                'by_event'     => (clone $q)
                    ->selectRaw('event, count(*) as count')
                    ->groupBy('event')
                    ->pluck('count', 'event')
                    ->toArray(),

                // BUG FIX: group only by causer_id to prevent duplicate causer_ids
                // (a user whose name changed would previously appear as two rows)
                'top_causers'  => (clone $q)
                    ->selectRaw('causer_id, MAX(causer_name) as causer_name, MAX(causer_type) as causer_type, count(*) as count')
                    ->whereNotNull('causer_id')
                    ->groupBy('causer_id')
                    ->orderByDesc('count')
                    ->limit(10)
                    ->get()
                    ->toArray(),

                'top_subjects' => (clone $q)
                    ->selectRaw('subject_type, count(*) as count')
                    ->whereNotNull('subject_type')
                    ->groupBy('subject_type')
                    ->orderByDesc('count')
                    ->limit(10)
                    ->get()
                    ->toArray(),

                'top_ips'      => (clone $q)
                    ->selectRaw('ip_address, count(*) as count')
                    ->whereNotNull('ip_address')
                    ->groupBy('ip_address')
                    ->orderByDesc('count')
                    ->limit(10)
                    ->get()
                    ->toArray(),

                'hourly'       => (clone $q)
                    ->selectRaw('HOUR(created_at) as hour, count(*) as count')
                    ->groupBy('hour')
                    ->orderBy('hour')
                    ->pluck('count', 'hour')
                    ->toArray(),

                'daily'        => (clone $q)
                    ->selectRaw('DATE(created_at) as day, count(*) as count')
                    ->groupBy('day')
                    ->orderBy('day')
                    ->limit(30)
                    ->get()
                    ->map(fn($r) => ['day' => $r->day, 'count' => $r->count])
                    ->toArray(),

                'alerts_fired' => DB::table('audit_alert_history')
                    ->when($from, fn($q2) => $q2->where('fired_at', '>=', $from))
                    ->when($to,   fn($q2) => $q2->where('fired_at', '<=', $to))
                    ->count(),
            ];
        });
    }

    public function invalidateCache(): void
    {
        Cache::forget('audit:stats:'.md5(':'));
    }
}
