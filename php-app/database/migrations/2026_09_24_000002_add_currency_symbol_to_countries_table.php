<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenant SaaS pivot phase 4 (docs/MIGRATION_MATRIX.md's own entry for
 * this migration has the full context). Phase 3 made VatLifecycleService/
 * InvoiceCalculator consult `countries.currency_code` instead of a
 * hardcoded 'NAD'; this phase sweeps the ~150 mechanical NAD/N$/NamRA
 * *display* literals in controllers and Blade views the same way -- and
 * those views show a currency *symbol* ('N$'), not the ISO code ('NAD'),
 * a distinction `countries` had no column for yet.
 *
 * `currency_symbol` is added nullable, backfilled for every existing row
 * (today: just 'NA' -> 'N$'), then tightened to NOT NULL with a
 * database-level DEFAULT of 'N$' -- the same backward-compatible pattern
 * `organisations.tax_authority_id` established in phase 2's own migration
 * (see that migration's own doc comment): a caller that says nothing about
 * a country's currency symbol still gets today's only answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->string('currency_symbol', 5)->nullable()->after('currency_code');
        });

        DB::table('countries')->where('code', 'NA')->update(['currency_symbol' => 'N$']);
        DB::table('countries')->whereNull('currency_symbol')->update(['currency_symbol' => 'N$']);

        Schema::table('countries', function (Blueprint $table) {
            $table->string('currency_symbol', 5)->nullable(false)->default('N$')->change();
        });
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropColumn('currency_symbol');
        });
    }
};
