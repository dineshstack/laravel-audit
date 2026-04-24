<?php
// config/audit.php

return [

    /*
    |--------------------------------------------------------------------------
    | API Authentication Token
    |--------------------------------------------------------------------------
    */
    'token' => env('AUDIT_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Retention (days)
    |--------------------------------------------------------------------------
    | Logs older than this are pruned by the audit:prune command.
    */
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Masked fields
    |--------------------------------------------------------------------------
    | Values of these fields are replaced with [REDACTED] in stored diffs.
    | Matching is case-insensitive substring. Add comma-separated env override.
    */
    'masked_fields' => array_filter(array_map('trim', explode(',',
        env('AUDIT_MASKED_FIELDS', 'password,token,secret,api_key,card_number,cvv,ssn,remember_token')
    ))),

    /*
    |--------------------------------------------------------------------------
    | Suspicious activity thresholds
    |--------------------------------------------------------------------------
    | Defaults that apply unless overridden by custom rules in the DB.
    */
    'alert_thresholds' => [
        'deletes_per_min'    => (int) env('AUDIT_ALERT_DELETES_PER_MIN',  10),
        'logins_per_min'     => (int) env('AUDIT_ALERT_LOGINS_PER_MIN',   20),
        'exports_per_hour'   => (int) env('AUDIT_ALERT_EXPORTS_PER_HOUR',  5),
        'updates_per_min'    => (int) env('AUDIT_ALERT_UPDATES_PER_MIN',  50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default alert channels
    |--------------------------------------------------------------------------
    */
    'alert_channels' => ['email', 'slack'],

    /*
    |--------------------------------------------------------------------------
    | Alert: Mailgun
    |--------------------------------------------------------------------------
    */
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

    /*
    |--------------------------------------------------------------------------
    | Pro features (license-gated)
    |--------------------------------------------------------------------------
    */
    'license_key' => env('AUDIT_LICENSE_KEY'),

];
