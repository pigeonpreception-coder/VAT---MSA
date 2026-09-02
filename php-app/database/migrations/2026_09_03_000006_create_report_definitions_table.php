<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `report_definitions` table -- Module 22's
 * fixed report catalogue, the anchor `report_runs`/`data_products` build
 * on. No command references this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->string('audience', 40);
            $table->text('description');
            $table->string('classification', 30);
            $table->string('query_version', 20);
            $table->string('status', 20);
            $table->timestamp('created_at')->useCurrent();
            $table->string('freshness_tier', 20)->default('DAILY');
            $table->text('guardrail')->nullable(false)->default('');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_definitions');
    }
};
