<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `security_detection_rules` table -- Module
 * 22's security-operations sub-domain: a threshold/window rule that
 * groups `security_events` into `security_incidents`. No command
 * references this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_detection_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->text('description');
            $table->string('event_type', 60);
            $table->string('group_by', 60);
            $table->unsignedInteger('threshold_count');
            $table->unsignedInteger('window_minutes');
            $table->string('severity', 20);
            $table->string('status', 20);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_detection_rules');
    }
};
