<?php
// src/Services/AlertService.php
// Phase 2: Suspicious-activity detection with default thresholds + configurable overrides
// Fires via Mailgun email and/or Slack webhook, throttled per rule.

namespace Dineshstack\LaravelAudit\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Dineshstack\LaravelAudit\Models\AuditEntry;

class AlertService
{
    private Client $http;

    // Default thresholds (overridden by audit.php and DB rules)
    private array $defaults = [
        'deletes_per_min'    => 10,
        'logins_per_min'     => 20,
        'exports_per_hour'   => 5,
        'updates_per_min'    => 50,
    ];

    public function __construct()
    {
        $this->http = new Client(['timeout' => 8, 'http_errors' => false]);

        // Merge config overrides into defaults
        $this->defaults = array_merge($this->defaults, [
            'deletes_per_min'  => (int) config('audit.alert_thresholds.deletes_per_min',  $this->defaults['deletes_per_min']),
            'logins_per_min'   => (int) config('audit.alert_thresholds.logins_per_min',   $this->defaults['logins_per_min']),
            'exports_per_hour' => (int) config('audit.alert_thresholds.exports_per_hour', $this->defaults['exports_per_hour']),
            'updates_per_min'  => (int) config('audit.alert_thresholds.updates_per_min',  $this->defaults['updates_per_min']),
        ]);
    }

    /**
     * Called immediately after each AuditEntry is saved.
     * Checks built-in patterns + custom DB rules.
     */
    public function checkPattern(AuditEntry $entry): void
    {
        $causerKey = "{$entry->causer_type}:{$entry->causer_id}";

        // ── Built-in pattern: rapid deletes ──────────────────────────────────
        if ($entry->event === 'delete') {
            $count = $this->increment("audit:rate:delete:{$causerKey}", 60);
            if ($count >= $this->defaults['deletes_per_min']) {
                $this->fireSuspiciousAlert(
                    "Rapid deletes detected — {$count} in 1 min",
                    'deletes_per_min',
                    $count,
                    $this->defaults['deletes_per_min'],
                    $entry,
                );
            }
        }

        // ── Built-in pattern: rapid logins ────────────────────────────────────
        if (str_starts_with($entry->event, 'login')) {
            $count = $this->increment("audit:rate:login:{$entry->ip_address}", 60);
            if ($count >= $this->defaults['logins_per_min']) {
                $this->fireSuspiciousAlert(
                    "Login flood from {$entry->ip_address} — {$count} in 1 min",
                    'logins_per_min',
                    $count,
                    $this->defaults['logins_per_min'],
                    $entry,
                );
            }
        }

        // ── Custom DB rules ───────────────────────────────────────────────────
        $rules = Cache::remember('audit:custom_rules', 300, fn() =>
            DB::table('audit_alert_rules')->where('enabled', true)->get()
        );

        foreach ($rules as $rule) {
            $pattern = $rule->event_pattern;
            if ($pattern !== '*' && !str_starts_with($entry->event, $pattern)) continue;

            $count = $this->increment("audit:custom:{$rule->id}:{$causerKey}", $this->windowSeconds($rule->metric));
            if ($count >= $rule->threshold) {
                $this->fireCustomAlert($rule, $count, $entry);
            }
        }
    }

    // ── CRUD for custom alert rules ───────────────────────────────────────────

    public function allRules(): array
    {
        return DB::table('audit_alert_rules')->orderBy('created_at')->get()->toArray();
    }

    public function createRule(array $data): object
    {
        $id = DB::table('audit_alert_rules')->insertGetId([
            'name'          => $data['name'],
            'event_pattern' => $data['event_pattern'],
            'metric'        => $data['metric'],
            'threshold'     => $data['threshold'],
            'channels'      => json_encode($data['channels'] ?? ['email', 'slack']),
            'enabled'       => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        Cache::forget('audit:custom_rules');
        return DB::table('audit_alert_rules')->find($id);
    }

    public function updateRule(int $id, array $data): bool
    {
        Cache::forget('audit:custom_rules');
        return DB::table('audit_alert_rules')->where('id', $id)
            ->update(array_merge($data, ['updated_at' => now()])) > 0;
    }

    public function deleteRule(int $id): bool
    {
        Cache::forget('audit:custom_rules');
        return DB::table('audit_alert_rules')->where('id', $id)->delete() > 0;
    }

    public function alertHistory(int $limit = 50): array
    {
        return DB::table('audit_alert_history')
            ->orderByDesc('fired_at')
            ->limit($limit)
            ->get()->toArray();
    }

    public function defaultThresholds(): array
    {
        return $this->defaults;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function fireSuspiciousAlert(string $title, string $metric, int $value, int $threshold, AuditEntry $entry): void
    {
        $throttleKey = "audit:alert_throttle:{$metric}:{$entry->causer_id}";
        if (Cache::has($throttleKey)) return;
        Cache::put($throttleKey, true, 900); // 15-min throttle

        $channels = config('audit.alert_channels', ['email', 'slack']);
        $this->dispatch($title, $metric, $value, $threshold, $entry->causer_name, $entry->ip_address, $channels);

        DB::table('audit_alert_history')->insert([
            'rule_id'      => null,
            'rule_name'    => $title,
            'metric'       => $metric,
            'value'        => $value,
            'threshold'    => $threshold,
            'causer_name'  => $entry->causer_name,
            'ip_address'   => $entry->ip_address,
            'channels'     => json_encode($channels),
            'fired_at'     => now(),
        ]);
    }

    private function fireCustomAlert(object $rule, int $value, AuditEntry $entry): void
    {
        $throttleKey = "audit:alert_throttle:custom:{$rule->id}:{$entry->causer_id}";
        if (Cache::has($throttleKey)) return;
        Cache::put($throttleKey, true, 900);

        $channels = json_decode($rule->channels ?? '[]', true) ?: ['email', 'slack'];
        $this->dispatch($rule->name, $rule->metric, $value, $rule->threshold, $entry->causer_name, $entry->ip_address, $channels);

        DB::table('audit_alert_history')->insert([
            'rule_id'      => $rule->id,
            'rule_name'    => $rule->name,
            'metric'       => $rule->metric,
            'value'        => $value,
            'threshold'    => $rule->threshold,
            'causer_name'  => $entry->causer_name,
            'ip_address'   => $entry->ip_address,
            'channels'     => $rule->channels,
            'fired_at'     => now(),
        ]);
    }

    private function dispatch(string $title, string $metric, int $value, int $threshold, ?string $causer, ?string $ip, array $channels): void
    {
        try {
            if (in_array('email', $channels)) $this->sendEmail($title, $metric, $value, $threshold, $causer, $ip);
            if (in_array('slack', $channels)) $this->sendSlack($title, $metric, $value, $threshold, $causer, $ip);
        } catch (\Throwable $e) {
            Log::error('Audit alert dispatch failed: '.$e->getMessage());
        }
    }

    private function sendEmail(string $title, string $metric, int $value, int $threshold, ?string $causer, ?string $ip): void
    {
        $cfg = config('audit.alerts.email');
        if (empty($cfg['mailgun_key']) || empty($cfg['to'])) return;

        $body = "Alert: {$title}\nMetric: {$metric}\nValue: {$value} (threshold: {$threshold})\nCauser: {$causer}\nIP: {$ip}\nTime: ".now();
        $form = new \GuzzleHttp\Psr7\MultipartStream([
            ['name' => 'from',    'contents' => "Audit Log <{$cfg['from']}>"],
            ['name' => 'to',      'contents' => $cfg['to']],
            ['name' => 'subject', 'contents' => "🚨 Audit Alert: {$title}"],
            ['name' => 'text',    'contents' => $body],
        ]);

        $this->http->post("https://api.mailgun.net/v3/{$cfg['mailgun_domain']}/messages", [
            'auth' => ['api', $cfg['mailgun_key']],
            'body' => $form,
            'headers' => ['Content-Type' => 'multipart/form-data; boundary='.$form->getBoundary()],
        ]);
    }

    private function sendSlack(string $title, string $metric, int $value, int $threshold, ?string $causer, ?string $ip): void
    {
        $url = config('audit.alerts.slack.webhook_url');
        if (!$url) return;

        $this->http->post($url, ['json' => [
            'text' => "🚨 *Audit Alert: {$title}*",
            'blocks' => [
                ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => "🚨 {$title}"]],
                ['type' => 'section', 'fields' => [
                    ['type' => 'mrkdwn', 'text' => "*Metric:*\n`{$metric}`"],
                    ['type' => 'mrkdwn', 'text' => "*Value:*\n{$value} (limit: {$threshold})"],
                    ['type' => 'mrkdwn', 'text' => "*Causer:*\n".($causer ?? 'Unknown')],
                    ['type' => 'mrkdwn', 'text' => "*IP:*\n".($ip ?? '—')],
                    ['type' => 'mrkdwn', 'text' => "*Time:*\n".now()->toDateTimeString()],
                ]],
            ],
        ]]);
    }

    private function increment(string $key, int $ttlSeconds): int
    {
        $count = Cache::get($key, 0) + 1;
        Cache::put($key, $count, $ttlSeconds);
        return $count;
    }

    private function windowSeconds(string $metric): int
    {
        return match (true) {
            str_ends_with($metric, '_per_min')  => 60,
            str_ends_with($metric, '_per_hour') => 3600,
            str_ends_with($metric, '_per_day')  => 86400,
            default => 60,
        };
    }
}
