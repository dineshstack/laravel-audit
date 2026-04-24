<?php
// src/Console/Commands/InstallCommand.php

namespace Dineshstack\LaravelAudit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    protected $signature   = 'audit:install';
    protected $description = 'Install Laravel Audit — publishes config, migration and seeds sample data';

    public function handle(): int
    {
        $this->info('📦  Installing Laravel Audit...');

        $this->callSilently('vendor:publish', ['--tag' => 'audit-config',     '--force' => true]);
        $this->line('  ✅  config/audit.php published');

        $this->callSilently('vendor:publish', ['--tag' => 'audit-migrations', '--force' => true]);
        $this->line('  ✅  Migration published');

        if ($this->confirm('Run migrations now?', true)) {
            $this->call('migrate');
        }

        // Append .env stubs
        $env = base_path('.env');
        if (File::exists($env) && !str_contains(File::get($env), 'AUDIT_TOKEN')) {
            File::append($env, "\n# Laravel Audit\nAUDIT_TOKEN=change_me\nAUDIT_RETENTION_DAYS=90\nAUDIT_MASKED_FIELDS=password,token,card_number\nAUDIT_SLACK_WEBHOOK_URL=\nAUDIT_MAILGUN_API_KEY=\nAUDIT_MAILGUN_DOMAIN=\nAUDIT_ALERT_TO=\n");
            $this->line('  ✅  Env stubs added to .env');
        }

        $this->newLine();
        $this->info('✅  Laravel Audit installed!');
        $this->line('');
        $this->line('  Add LogsActivity trait to your models:');
        $this->line('    use Dineshstack\LaravelAudit\Traits\LogsActivity;');
        $this->newLine();
        $this->line('  Manual logging:');
        $this->line('    AuditLog::log(\'payment.processed\')->on($invoice)->with([\'amount\' => 500])->save();');
        $this->newLine();
        $this->line('  Dashboard: http://localhost:3001');

        return self::SUCCESS;
    }
}
