# dineshstack/laravel-audit

**Activity log & audit trail for Laravel 13.** Auto-log every Eloquent model change — with field-level diffs, IP capture, user attribution, batch grouping, data masking, and a full REST API — in one `composer require`.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/dineshstack/laravel-audit.svg?style=flat-square)](https://packagist.org/packages/dineshstack/laravel-audit)
[![Total Downloads](https://img.shields.io/packagist/dt/dineshstack/laravel-audit.svg?style=flat-square)](https://packagist.org/packages/dineshstack/laravel-audit)
[![PHP Version](https://img.shields.io/badge/php-%5E8.3-blue?style=flat-square)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-13-red?style=flat-square)](https://laravel.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg?style=flat-square)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-passing-brightgreen?style=flat-square)](#testing)

---

## Why this package?

Most Laravel audit packages store a flat JSON blob and call it done. `laravel-audit` goes further:

- **Field-level diffs** — exactly which columns changed, from what value, to what value
- **Sensitive-field masking** — `password`, `api_key`, `card_number`, and any custom field are stored as `[REDACTED]` automatically
- **Batch grouping** — wrap multiple operations in `AuditLog::batch()` and every entry shares a `batch_id` so you can replay a full transaction
- **Alert rules** — define threshold rules (e.g. "more than 10 deletes per minute") that fire Mailgun emails or Slack webhooks
- **Retention pruning** — auto-scheduled daily cleanup via `audit:prune`; configurable retention window
- **REST API** — nine endpoints covering feed, timeline, stats, export (CSV + PDF), and alert rule management, ready to power any dashboard or SIEM integration

---

## Requirements

| Dependency          | Version |
| ------------------- | ------- |
| PHP                 | ^8.3    |
| Laravel             | ^13.0   |
| `league/csv`        | ^9.15   |
| `dompdf/dompdf`     | ^2.0    |
| `guzzlehttp/guzzle` | ^7.8    |

---

## Installation

```bash
composer require dineshstack/laravel-audit
php artisan audit:install
```

`audit:install` publishes `config/audit.php`, publishes and optionally runs the migration, and appends the required environment variable stubs to your `.env` file.

If you prefer to publish assets manually:

```bash
php artisan vendor:publish --tag=audit-config
php artisan vendor:publish --tag=audit-migrations
php artisan migrate
```

---

## Quick start

### 1. Auto-log any Eloquent model

Add the `LogsActivity` trait to the models you want to audit:

```php
use Dineshstack\LaravelAudit\Traits\LogsActivity;

class Invoice extends Model
{
    use LogsActivity;
}
```

That's it. Every `create`, `update`, and `delete` on `Invoice` is now logged with the changed attributes, old and new values, the authenticated user, their IP address, user agent, and the full request URL.

### 2. Manual logging

Use the fluent builder for events that aren't model-driven:

```php
use Dineshstack\LaravelAudit\Facades\AuditLog;

AuditLog::log('payment.processed')
    ->on($invoice)
    ->with(['amount' => 500, 'currency' => 'USD'])
    ->by($user)
    ->description('Stripe charge succeeded')
    ->save();
```

All methods except `log()` and `save()` are optional:

| Method                        | Description                                         |
| ----------------------------- | --------------------------------------------------- |
| `->on(Model $subject)`        | The model being acted upon                          |
| `->with(array $data)`         | Arbitrary context stored in `properties`            |
| `->by(?Model $causer)`        | Who did it — defaults to `auth()->user()`           |
| `->description(string $desc)` | Human-readable label stored alongside the event key |

### 3. Batch logging

Wrap multiple operations in `AuditLog::batch()` to link them under a shared `batch_id`. This lets you query or replay an entire multi-step transaction as a unit:

```php
AuditLog::batch(function () use ($order) {
    $order->update(['status' => 'shipped']);
    $order->items()->each->update(['dispatched' => true]);
});
```

All entries created inside the closure share the same UUID `batch_id`.

---

## Field-level diffs

When a model is updated, the package computes a precise diff of only the changed fields:

```json
{
  "old": { "status": "pending", "total": 450 },
  "new": { "status": "shipped", "total": 500 },
  "diff": {
    "status": { "old": "pending", "new": "shipped", "type": "changed" },
    "total": { "old": 450, "new": 500, "type": "changed" }
  }
}
```

Unchanged fields are never stored. Added fields report `"type": "added"` with `old: null`; removed fields report `"type": "removed"` with `new: null`.

### Controlling which fields are logged

Override these two methods in your model:

```php
class User extends Model
{
    use LogsActivity;

    // Never log these columns
    protected function getAuditExclude(): array
    {
        return ['updated_at', 'created_at', 'remember_token', 'two_factor_secret'];
    }

    // Log ONLY these columns (overrides exclude; empty = log all minus excluded)
    protected function getAuditInclude(): array
    {
        return ['name', 'email', 'role'];
    }
}
```

---

## Data masking

Any field whose name contains a masked keyword is stored as `[REDACTED]` in both the `old`/`new` snapshots and the diff. The default masked keywords are:

```
password, token, secret, api_key, card_number, cvv, ssn, remember_token
```

Customize via `.env` (comma-separated, case-insensitive substring matching):

```env
AUDIT_MASKED_FIELDS=password,token,secret,api_key,card_number,pin,ssn
```

Or publish and edit `config/audit.php` directly.

---

## REST API

All endpoints are prefixed with `/api/audit` and optionally protected by a token set in `AUDIT_TOKEN`. Pass the token as either:

- `X-Audit-Token: your_token` header
- `Authorization: Bearer your_token` header

If `AUDIT_TOKEN` is empty, all endpoints are unauthenticated (suitable for internal networks).

### Endpoints

#### `GET /api/audit/feed`

Paginated activity feed with filtering.

**Query parameters:**

| Parameter      | Type    | Description                                               |
| -------------- | ------- | --------------------------------------------------------- |
| `event`        | string  | Filter by event name (e.g. `update`, `payment.processed`) |
| `causer_id`    | integer | Filter by user ID                                         |
| `subject_type` | string  | Filter by model class (e.g. `App\Models\Invoice`)         |
| `ip_address`   | string  | Filter by IP address                                      |
| `date_from`    | date    | Filter entries on or after this date                      |
| `date_to`      | date    | Filter entries on or before this date                     |
| `search`       | string  | Full-text search across description and properties        |
| `batch_id`     | string  | Show all entries in a specific batch                      |
| `per_page`     | integer | Results per page (default: 30, max: 100)                  |
| `page`         | integer | Page number                                               |

**Response:**

```json
{
  "data": [
    {
      "id": "01hwxyz...",
      "event": "update",
      "description": "Updated Invoice #42",
      "causer_id": 7,
      "causer_name": "Jane Smith",
      "subject_type": "App\\Models\\Invoice",
      "subject_id": "42",
      "ip_address": "192.168.1.1",
      "properties": {
        "old": { "status": "pending" },
        "new": { "status": "paid" },
        "diff": {
          "status": { "old": "pending", "new": "paid", "type": "changed" }
        }
      },
      "created_at": "2025-04-24T09:30:00.000000Z"
    }
  ],
  "meta": { "total": 1420, "per_page": 30, "current_page": 1 },
  "next_page_url": "/api/audit/feed?page=2"
}
```

#### `GET /api/audit/entry/{id}`

Single audit entry by ULID — includes full `properties` payload.

#### `GET /api/audit/timeline`

Chronological activity for a specific user, ordered by most recent.

| Parameter     | Description                   |
| ------------- | ----------------------------- |
| `causer_id`   | **Required.** The user's ID   |
| `causer_type` | Defaults to `App\Models\User` |
| `per_page`    | Default: 30                   |

#### `GET /api/audit/stats`

Aggregated statistics for a date range.

**Response shape:**

```json
{
  "total": 12400,
  "by_event": { "create": 3200, "update": 7100, "delete": 400 },
  "top_causers": [
    { "causer_id": 7, "causer_name": "Jane Smith", "count": 840 }
  ],
  "top_subjects": [{ "subject_type": "App\\Models\\Invoice", "count": 2100 }],
  "hourly": { "0": 12, "1": 8, "9": 210, "14": 340 },
  "daily": [{ "day": "2025-04-24", "count": 480 }]
}
```

#### `GET /api/audit/causers`

Distinct causers with activity counts — for populating filter dropdowns. Returns the most recently seen name per `causer_id`, so renamed users appear only once.

#### `GET /api/audit/export/csv`

Exports the filtered log as a `.csv` file. Accepts the same filter parameters as `/feed`. Maximum 10,000 rows per export; exports are tracked for alert rate-limiting.

#### `GET /api/audit/export/pdf`

Exports a formatted PDF audit trail report. Accepts the same filters as `/feed`. Powered by `dompdf/dompdf`.

#### Alert rule endpoints

| Method   | Endpoint                    | Description                           |
| -------- | --------------------------- | ------------------------------------- |
| `GET`    | `/api/audit/alerts`         | List all rules and default thresholds |
| `POST`   | `/api/audit/alerts`         | Create an alert rule                  |
| `PUT`    | `/api/audit/alerts/{id}`    | Update or enable/disable a rule       |
| `DELETE` | `/api/audit/alerts/{id}`    | Delete a rule                         |
| `GET`    | `/api/audit/alerts/history` | Recent fired-alert history            |

**Create rule request body:**

```json
{
  "name": "Bulk delete guard",
  "event_pattern": "delete",
  "metric": "deletes_per_min",
  "threshold": 10,
  "channels": ["email", "slack"]
}
```

---

## Alert rules

Alert rules fire when a threshold is exceeded. The package checks patterns synchronously after every `AuditEntry` is saved.

### Default thresholds (configurable via `.env`)

| Rule               | Default | Environment variable           |
| ------------------ | ------- | ------------------------------ |
| Deletes per minute | 10      | `AUDIT_ALERT_DELETES_PER_MIN`  |
| Logins per minute  | 20      | `AUDIT_ALERT_LOGINS_PER_MIN`   |
| Updates per minute | 50      | `AUDIT_ALERT_UPDATES_PER_MIN`  |
| Exports per hour   | 5       | `AUDIT_ALERT_EXPORTS_PER_HOUR` |

Custom rules created via the API override these defaults for matching event patterns.

### Notification channels

**Mailgun (email):**

```env
AUDIT_MAILGUN_API_KEY=key-xxx
AUDIT_MAILGUN_DOMAIN=mg.yourdomain.com
AUDIT_ALERT_FROM=audit@yourdomain.com
AUDIT_ALERT_TO=security@yourdomain.com
```

**Slack:**

```env
AUDIT_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/xxx/yyy/zzz
```

---

## Retention pruning

Logs are automatically pruned daily by a scheduled command registered in the service provider. The retention window defaults to 90 days:

```env
AUDIT_RETENTION_DAYS=90
```

Run pruning manually at any time:

```bash
php artisan audit:prune --days=90
```

Ensure your Laravel scheduler is running:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

---

## Database schema

Three tables are created by the package migration:

### `audit_logs`

| Column         | Type      | Description                                        |
| -------------- | --------- | -------------------------------------------------- |
| `id`           | ULID (PK) | Universally unique, lexicographically sortable     |
| `event`        | string    | Event key, e.g. `update`, `payment.processed`      |
| `description`  | string    | Human-readable label                               |
| `batch_id`     | string    | Groups entries from one `AuditLog::batch()` call   |
| `subject_type` | string    | Audited model class                                |
| `subject_id`   | string    | Audited model primary key                          |
| `causer_type`  | string    | Actor model class (usually `App\Models\User`)      |
| `causer_id`    | integer   | Actor primary key                                  |
| `causer_name`  | string    | Snapshot of the actor's name at time of action     |
| `properties`   | JSON      | `old`, `new`, `diff`, and any custom `with()` data |
| `ip_address`   | string    | Request IP (supports IPv6)                         |
| `user_agent`   | text      | Full user-agent string                             |
| `url`          | text      | Full request URL                                   |
| `method`       | string    | HTTP method                                        |
| `created_at`   | timestamp | Indexed for fast date-range queries                |

Indexes on: `event`, `batch_id`, `ip_address`, `created_at`, and composite morphs indexes on `(subject_type, subject_id)` and `(causer_type, causer_id)`.

### `audit_alert_rules`

Stores custom threshold rules created via the API.

### `audit_alert_history`

Records every fired alert with the rule name, metric value, threshold breached, and the channels notified.

---

## Configuration reference

After publishing with `php artisan vendor:publish --tag=audit-config`, your `config/audit.php` exposes:

```php
return [
    // Bearer token protecting the REST API (leave empty to disable auth)
    'token' => env('AUDIT_TOKEN', ''),

    // Prune logs older than this many days
    'retention_days' => env('AUDIT_RETENTION_DAYS', 90),

    // Fields redacted in stored diffs (case-insensitive substring match)
    'masked_fields' => ['password', 'token', 'secret', 'api_key',
                        'card_number', 'cvv', 'ssn', 'remember_token'],

    // Default alert thresholds (overridden by DB rules per event pattern)
    'alert_thresholds' => [
        'deletes_per_min'  => 10,
        'logins_per_min'   => 20,
        'exports_per_hour' => 5,
        'updates_per_min'  => 50,
    ],

    // Active notification channels
    'alert_channels' => ['email', 'slack'],

    // Mailgun + Slack credentials
    'alerts' => [
        'email' => [
            'mailgun_key'    => env('AUDIT_MAILGUN_API_KEY'),
            'mailgun_domain' => env('AUDIT_MAILGUN_DOMAIN'),
            'from'           => env('AUDIT_ALERT_FROM', 'audit@yourdomain.com'),
            'to'             => env('AUDIT_ALERT_TO'),
        ],
        'slack' => [
            'webhook_url' => env('AUDIT_SLACK_WEBHOOK_URL'),
        ],
    ],
];
```

---

## Testing

The package ships with a PHPUnit test suite covering `DiffService` and `MaskingService`:

```bash
composer install
vendor/bin/phpunit
```

**Test coverage includes:**

- Detecting changed, added, and removed fields in diffs
- Returning an empty diff for identical attribute sets
- Redacting password and token fields (including nested arrays)
- Case-insensitive masking of fields like `Password` and `API_KEY`

To run tests against a specific Laravel application using Testbench:

```bash
composer require --dev orchestra/testbench
vendor/bin/phpunit
```

---

## Pro Dashboard

The free package exposes the REST API. To get a full visual interface — activity feed, user timeline, statistics charts, field diff viewer, CSV/PDF export, and alert rule management — pick up the **Pro Dashboard**:

👉 **[dineshstack.com/laravel-audit](https://dineshstack.com/laravel-audit)**

Built with Next.js 16, React 19, Recharts, and TanStack Query. Self-hosted, dark/light mode, Docker-ready.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for recent changes.

## Contributing

Pull requests are welcome. Please open an issue first to discuss significant changes. All contributions must include tests.

## Security

If you discover a security vulnerability, please email **security@dineshstack.com** instead of opening a public issue. All disclosures are reviewed within 48 hours.

## License

MIT. See [LICENSE](LICENSE) for the full text.

## Author

**Dinesh Wijethunga** — [dineshwijethunga.me](https://dineshwijethunga.me)
