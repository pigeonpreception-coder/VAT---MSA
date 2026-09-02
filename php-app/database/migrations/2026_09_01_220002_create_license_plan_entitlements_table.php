<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `license_plan_entitlements` table -- which features a plan grants, and at what limit. Seed-only, like its two parent tables. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_plan_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('license_plan_id')->constrained('license_plans');
            $table->string('feature_key', 60);
            $table->foreign('feature_key')->references('feature_key')->on('license_features');
            $table->boolean('enabled')->default(true);
            $table->integer('limit_value')->nullable();
            $table->text('configuration')->default('{}');

            $table->unique(['license_plan_id', 'feature_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_plan_entitlements');
    }
};
