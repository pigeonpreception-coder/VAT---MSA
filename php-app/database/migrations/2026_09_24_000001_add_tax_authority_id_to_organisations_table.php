<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenant SaaS pivot phase 2 (docs/MIGRATION_MATRIX.md's own entry
 * for this migration has the full context). Phase 1 freed up the word
 * "tenant" (App\Support\Access\TenantScope -> TaxpayerScope); this phase
 * gives `organisations` -- previously scoped only to a `taxpayer_id`,
 * with no concept of which licensed national platform it belongs to --
 * a real `tax_authority_id` foreign key into the Authority Governance
 * module's own `tax_authorities` table (`countries` -> `tax_jurisdictions`
 * -> `tax_authorities`), which already existed, unused as a scoping
 * mechanism, seeded with exactly one row each for Namibia/NAMRA by
 * Database\Seeders\AuthorityGovernanceSeeder.
 *
 * The three reference rows are inserted here too, not left to that
 * seeder alone: a fresh test database (RefreshDatabase re-runs every
 * migration but only the seeders a test file explicitly calls
 * $this->seed() for) must never fail this migration's own backfill/
 * default for lack of a row to point at. `updateOrInsert` with the
 * seeder's own exact IDs/values means whichever of this migration or
 * the seeder runs first, the other is a harmless no-op -- there is
 * still exactly one writer of what these three rows' *content* should
 * be (the seeder), this migration only guarantees they exist by the
 * time it needs them.
 *
 * `tax_authority_id` is added nullable, backfilled, then tightened to
 * NOT NULL with a database-level DEFAULT of NAMRA's own id -- not just
 * backfilled and left nullable -- specifically so every one of this
 * migration set's own ~50 existing test fixtures (and any future one)
 * that build an Organisation without naming a tax authority keep
 * working unchanged: the column is real and enforced, but a caller that
 * says nothing about which authority still gets today's only answer.
 * Tightening this default away (once a second tenant is real and an
 * omitted authority should be an error, not an assumption) is a later
 * phase's job, not this one's.
 */
return new class extends Migration
{
    private const DEFAULT_TAX_AUTHORITY_ID = 'tax-authority-na-namra';

    public function up(): void
    {
        DB::table('countries')->updateOrInsert(
            ['code' => 'NA'],
            ['iso3_code' => 'NAM', 'name' => 'Namibia', 'currency_code' => 'NAD', 'status' => 'ACTIVE', 'created_at' => now()],
        );
        DB::table('tax_jurisdictions')->updateOrInsert(
            ['id' => 'tax-jurisdiction-na-national'],
            ['country_code' => 'NA', 'code' => 'NA-NATIONAL', 'name' => 'Namibia national tax jurisdiction', 'status' => 'ACTIVE', 'created_at' => now()],
        );
        DB::table('tax_authorities')->updateOrInsert(
            ['id' => self::DEFAULT_TAX_AUTHORITY_ID],
            ['jurisdiction_id' => 'tax-jurisdiction-na-national', 'code' => 'NAMRA', 'name' => 'Namibia Revenue Agency', 'status' => 'ACTIVE', 'created_at' => now()],
        );

        Schema::table('organisations', function (Blueprint $table) {
            $table->uuid('tax_authority_id')->nullable()->after('taxpayer_id');
        });

        DB::table('organisations')->whereNull('tax_authority_id')->update(['tax_authority_id' => self::DEFAULT_TAX_AUTHORITY_ID]);

        Schema::table('organisations', function (Blueprint $table) {
            $table->uuid('tax_authority_id')->nullable(false)->default(self::DEFAULT_TAX_AUTHORITY_ID)->change();
            $table->foreign('tax_authority_id')->references('id')->on('tax_authorities');
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->dropForeign(['tax_authority_id']);
            $table->dropColumn('tax_authority_id');
        });
    }
};
