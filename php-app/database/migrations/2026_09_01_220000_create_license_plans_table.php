<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `license_plans` table -- Phase 12's own
 * "portals/licensing/governance" scope, starting with Licensing &
 * Entitlements (`lib/data/control-plane-repository.ts`'s
 * getEntitlementsSnapshot/getUsageSnapshot/changeLicenseState/
 * upgradeLicense). Like `tax_rule_sets` before it, the source has no
 * application command that ever creates a plan -- which plans exist at
 * all is seed-only, deploy-time governance data (see LicensePlanSeeder's
 * own doc comment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 60);
            $table->string('name');
            $table->unsignedInteger('version');
            $table->string('status', 20);
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['code', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_plans');
    }
};
