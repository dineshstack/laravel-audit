<?php
// src/AuditServiceProvider.php

namespace Dineshstack\LaravelAudit;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Dineshstack\LaravelAudit\Services\AlertService;
use Dineshstack\LaravelAudit\Services\SearchService;
use Dineshstack\LaravelAudit\Services\ExportService;
use Dineshstack\LaravelAudit\Services\DiffService;
use Dineshstack\LaravelAudit\Services\MaskingService;

class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/audit.php', 'audit');

        $this->app->singleton(DiffService::class);
        $this->app->singleton(MaskingService::class);
        $this->app->singleton(AlertService::class);
        $this->app->singleton(SearchService::class);
        $this->app->singleton(ExportService::class);

        // Bind the main AuditLogger (resolved by Facade)
        $this->app->singleton('audit', fn($app) => new AuditLogger(
            $app->make(DiffService::class),
            $app->make(MaskingService::class),
            $app->make(AlertService::class),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        $this->publishes([
            __DIR__.'/../config/audit.php' => config_path('audit.php'),
        ], 'audit-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'audit-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Dineshstack\LaravelAudit\Console\Commands\InstallCommand::class,
                \Dineshstack\LaravelAudit\Console\Commands\PruneCommand::class,
            ]);
        }

        // Auto-schedule retention pruning daily
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $days = config('audit.retention_days', 90);
            $schedule->command("audit:prune --days={$days}")
                ->daily()
                ->withoutOverlapping();
        });
    }
}
