<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenant SaaS pivot phase 4 (docs/MIGRATION_MATRIX.md's own entry for
 * this migration has the full context). `tax_authorities.code` ('NAMRA',
 * uppercase, a technical identifier -- see that table's own seed rows) is
 * not the same string this codebase's own prose has always used for the
 * authority's name in running text: "NamRA", the real authority's own
 * stylized mixed-case branding. Deriving the Blade views this phase swept
 * from `code` directly would have silently reflowed every one of those
 * mentions to shout-cased "NAMRA" -- a real, visible regression for
 * today's only tenant this phase's own discipline (zero behaviour change
 * for NamRA/Namibia) forbids. `short_name` is a distinct column for
 * exactly that prose string, not a duplicate of `code`.
 *
 * Same backward-compatible pattern as `organisations.tax_authority_id`
 * (phase 2) and `countries.currency_symbol` (this phase, above): added
 * nullable, backfilled, then tightened to NOT NULL with a database-level
 * DEFAULT of 'NamRA' -- so every organisation resolving today's only
 * tenant keeps seeing exactly the same brand text it always has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_authorities', function (Blueprint $table) {
            $table->string('short_name', 60)->nullable()->after('code');
        });

        DB::table('tax_authorities')->where('id', 'tax-authority-na-namra')->update(['short_name' => 'NamRA']);
        DB::table('tax_authorities')->whereNull('short_name')->update(['short_name' => 'NamRA']);

        Schema::table('tax_authorities', function (Blueprint $table) {
            $table->string('short_name', 60)->nullable(false)->default('NamRA')->change();
        });
    }

    public function down(): void
    {
        Schema::table('tax_authorities', function (Blueprint $table) {
            $table->dropColumn('short_name');
        });
    }
};
