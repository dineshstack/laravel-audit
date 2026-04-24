<?php
// database/migrations/2024_01_01_000001_create_audit_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard: skip if already migrated (idempotent for re-runs / dev resets)
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('event');
            $table->string('description')->nullable();
            $table->string('batch_id')->nullable();

            // nullableMorphs() creates BOTH the columns AND a composite index
            // automatically: audit_logs_subject_type_subject_id_index
            // Do NOT call ->index(['subject_type','subject_id']) — that would
            // create a duplicate and throw on SQLite / MySQL.
            $table->nullableMorphs('subject');   // subject_type + subject_id + composite index

            // Same for causer — nullableMorphs creates audit_logs_causer_type_causer_id_index
            $table->nullableMorphs('causer');    // causer_type + causer_id + composite index
            $table->string('causer_name')->nullable();

            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('url')->nullable();
            $table->string('method', 10)->nullable();

            $table->timestamp('created_at')->useCurrent()->index();

            // Only add indexes NOT already created by nullableMorphs()
            $table->index('event');
            $table->index('batch_id');
            $table->index('ip_address');

            // REMOVED (were duplicates of nullableMorphs auto-indexes):
            // $table->index(['causer_type',  'causer_id']);   ← BUG: already exists
            // $table->index(['subject_type', 'subject_id']);  ← BUG: already exists
        });

        Schema::create('audit_alert_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('event_pattern');
            $table->string('metric');
            $table->unsignedInteger('threshold');
            $table->json('channels')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('audit_alert_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->string('rule_name');
            $table->string('metric');
            $table->float('value');
            $table->unsignedInteger('threshold');
            $table->string('causer_name')->nullable();
            $table->string('ip_address')->nullable();
            $table->json('channels')->nullable();
            $table->timestamp('fired_at');
            $table->index('fired_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_alert_history');
        Schema::dropIfExists('audit_alert_rules');
        Schema::dropIfExists('audit_logs');
    }
};
