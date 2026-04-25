<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_subject_type_subject_id_index');
            $table->string('subject_id', 36)->nullable()->change();
            $table->index(['subject_type', 'subject_id'], 'audit_logs_subject_type_subject_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_subject_type_subject_id_index');
            $table->unsignedBigInteger('subject_id')->nullable()->change();
            $table->index(['subject_type', 'subject_id'], 'audit_logs_subject_type_subject_id_index');
        });
    }
};
