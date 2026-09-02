<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `saas_conformance_runs` table -- a
 * `saas_applications` row's own per-environment conformance check
 * battery, the anchor `saas_environment_approvals` builds on. No command
 * references this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_conformance_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('saas_application_id')->constrained('saas_applications');
            $table->string('environment', 20);
            $table->string('test_suite_version', 20);
            $table->text('checks');
            $table->string('outcome', 20);
            $table->foreignUuid('submitted_by')->constrained('users');
            $table->timestamp('submitted_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_conformance_runs');
    }
};
