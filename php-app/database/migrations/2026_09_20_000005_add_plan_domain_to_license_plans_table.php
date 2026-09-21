<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `license_plans` table, which has always
 * carried `plan_domain TEXT NOT NULL CHECK (plan_domain IN
 * ('COMMERCIAL_SAAS','GOVERNMENT_TAX'))` -- a real schema gap in this
 * migration's own `license_plans` table (2026_09_01_220000), discovered
 * while building the Self-Serve Signup module: `submitSelfServeSignup`'s
 * plan lookup filters `plan_domain='COMMERCIAL_SAAS'`, and this column
 * never existed here at all. `plan-pilot-professional-v1`, the one plan
 * LicensePlanSeeder already seeds, is source's own COMMERCIAL_SAAS plan
 * (confirmed against db/runtime.ts's own seed statement) -- backfilled
 * as such below, matching source exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A plain new-column add with a default (not a ->change() on an
        // existing column, which this project's vendor tree can't run --
        // doctrine/dbal isn't installed) -- MySQL backfills every existing
        // row with the default automatically when a NOT NULL column with
        // DEFAULT is added.
        Schema::table('license_plans', function (Blueprint $table) {
            $table->string('plan_domain', 20)->default('COMMERCIAL_SAAS')->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('license_plans', function (Blueprint $table) {
            $table->dropColumn('plan_domain');
        });
    }
};
