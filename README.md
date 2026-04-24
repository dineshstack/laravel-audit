# dineshstack/laravel-audit

Activity log & audit trail for Laravel 13. Auto-logs create, update, delete 
events on any Eloquent model with old/new diffs, IP, user agent, and more.

## Installation

```bash
composer require dineshstack/laravel-audit
php artisan audit:install
```

## Usage

```php
// Add to any Eloquent model
use Dineshstack\LaravelAudit\Traits\LogsActivity;

class Invoice extends Model
{
    use LogsActivity;
}

// Manual logging
use Dineshstack\LaravelAudit\Facades\AuditLog;

AuditLog::log('payment.processed')
    ->on($invoice)
    ->with(['amount' => 500])
    ->by($user)
    ->save();

// Batch logging (all share same batch_id)
AuditLog::batch(function () use ($order) {
    $order->update(['status' => 'shipped']);
    $order->items()->each->update(['dispatched' => true]);
});
```

## Features (free)
- `LogsActivity` trait — auto-log create/update/delete
- Fluent `AuditLog::log()` builder
- Old/new attribute diffs stored as JSON
- Data masking for sensitive fields (password, token, etc.)
- Retention pruning: `php artisan audit:prune --days=90`
- REST API at `/api/audit/feed`

## Pro Dashboard
Full Next.js 16 dashboard with activity feed, user timeline, statistics,
diff viewer, CSV/PDF export, and Mailgun + Slack alerts.

👉 [dineshstack.com/laravel-audit](https://dineshstack.com/laravel-audit)