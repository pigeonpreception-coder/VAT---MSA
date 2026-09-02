<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `license_usage` table -- per-metric, per-period usage counters read back by GetUsage/GetEntitlements. Like `subscriptions`, seed/fixture-only in both systems; no application command writes it yet (metering is out of this slice's scope). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_usage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_license_id')->constrained('organisation_licenses');
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('metric_key', 60);
            $table->string('period_key', 20);
            $table->integer('used_value')->default(0);
            $table->integer('reserved_value')->default(0);
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['organisation_id', 'metric_key', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_usage');
    }
};
