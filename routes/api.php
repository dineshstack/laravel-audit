<?php
// routes/api.php

use Illuminate\Support\Facades\Route;
use Dineshstack\LaravelAudit\Http\Controllers\AuditController;
use Dineshstack\LaravelAudit\Http\Controllers\ExportController;
use Dineshstack\LaravelAudit\Http\Controllers\AlertsController;

Route::prefix(config('audit.route_prefix', 'api/audit'))->group(function () {
    // ── Feed & search ─────────────────────────────────────────────────────────
    Route::get('feed',        [AuditController::class, 'feed']);
    Route::get('entry/{id}',  [AuditController::class, 'show']);
    Route::get('timeline',    [AuditController::class, 'timeline']);
    Route::get('stats',       [AuditController::class, 'stats']);
    Route::get('causers',     [AuditController::class, 'causers']);

    // ── Exports ───────────────────────────────────────────────────────────────
    Route::get('export/csv',  [ExportController::class, 'csv']);
    Route::get('export/pdf',  [ExportController::class, 'pdf']);

    // ── Alert rules ───────────────────────────────────────────────────────────
    Route::get('alerts',         [AlertsController::class, 'index']);
    Route::post('alerts',        [AlertsController::class, 'store']);
    Route::put('alerts/{id}',    [AlertsController::class, 'update']);
    Route::delete('alerts/{id}', [AlertsController::class, 'destroy']);
    Route::get('alerts/history', [AlertsController::class, 'history']);
});
