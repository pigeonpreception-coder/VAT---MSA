<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `security_playbook_actions` table -- the
 * automated or analyst-taken response log against a `security_incidents`
 * row. No command references this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_playbook_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('incident_id')->constrained('security_incidents');
            $table->string('action_type', 40);
            $table->foreignUuid('actor_id')->nullable()->constrained('users');
            $table->boolean('automated')->default(false);
            $table->text('details');
            $table->timestamp('performed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_playbook_actions');
    }
};
