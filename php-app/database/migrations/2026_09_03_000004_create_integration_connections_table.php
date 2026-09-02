<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `integration_connections` table -- Module
 * 22's platform integration catalogue (both the platform/developer-portal
 * snapshots and `bank_imports`/`sync_jobs` reference it). No command
 * references this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->nullable()->constrained('organisations');
            $table->string('provider_key', 60);
            $table->string('category', 40);
            $table->string('display_name');
            $table->text('capabilities');
            $table->string('endpoint_reference')->nullable();
            $table->string('credential_reference')->nullable();
            $table->string('configuration_status', 30);
            $table->string('operational_status', 30);
            $table->string('data_classification', 30);
            $table->timestamp('last_health_check_at')->nullable();
            $table->string('last_health_outcome', 30)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['provider_key', 'organisation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_connections');
    }
};
