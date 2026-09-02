<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `security_incidents` table -- what a
 * `security_detection_rules` match (or a manually opened case) groups
 * `security_events` into, the anchor `security_playbook_actions` builds
 * on. No command references this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('severity', 20);
            $table->string('status', 20);
            $table->foreignUuid('source_event_id')->nullable()->constrained('security_events');
            $table->string('automated_action')->nullable();
            $table->string('owner')->nullable();
            $table->foreignUuid('detection_rule_id')->nullable()->constrained('security_detection_rules');
            $table->string('group_key')->nullable();
            $table->foreignUuid('subject_user_id')->nullable()->constrained('users');
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->text('resolution_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_incidents');
    }
};
