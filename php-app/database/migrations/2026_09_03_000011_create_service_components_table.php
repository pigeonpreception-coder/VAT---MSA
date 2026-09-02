<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `service_components` table -- the fixed
 * component inventory `getPlatformSnapshot`/`getTechnicalPlatformSnapshot`
 * (platform-repository.ts, not yet ported) reads. No command references
 * this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('component_key', 60)->unique();
            $table->string('display_name');
            $table->string('component_type', 40);
            $table->string('criticality', 20);
            $table->string('configuration_status', 30);
            $table->string('operational_status', 30);
            $table->text('dependency_summary');
            $table->timestamp('last_checked_at')->nullable();
            $table->text('status_detail');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_components');
    }
};
