<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `saas_applications` table -- Module 10
 * Phase C's SaaS marketplace, the anchor `saas_conformance_runs`/
 * `saas_environment_approvals` build on. No command references this
 * table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('saas_provider_id')->constrained('saas_providers');
            $table->string('name');
            $table->text('description');
            $table->text('requested_capabilities');
            $table->string('endpoint_reference');
            $table->string('status', 20);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_applications');
    }
};
