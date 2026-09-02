<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `saas_environment_approvals` table -- a
 * `saas_applications` row's own per-environment go-live decision, backed
 * by the `saas_conformance_runs` row that justified it. No command
 * references this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_environment_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('saas_application_id')->constrained('saas_applications');
            $table->string('environment', 20);
            $table->string('status', 20);
            $table->foreignUuid('conformance_run_id')->constrained('saas_conformance_runs');
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['saas_application_id', 'environment'], 'saas_environment_approvals_app_environment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_environment_approvals');
    }
};
