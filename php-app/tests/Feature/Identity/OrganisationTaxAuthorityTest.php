<?php

namespace Tests\Feature\Identity;

use App\Models\Organisation;
use App\Models\TaxAuthority;
use App\Models\Taxpayer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the multi-tenant SaaS pivot's phase 2 schema change: see
 * database/migrations/2026_09_24_000001_add_tax_authority_id_to_organisations_table.php's
 * own doc comment for the full context. This migration is deliberately
 * self-sufficient (inserts the NamRA reference rows itself, not relying
 * on Database\Seeders\AuthorityGovernanceSeeder having run), so these
 * tests intentionally do NOT call $this->seed(AuthorityGovernanceSeeder::class)
 * -- proving the migration alone is enough is the point.
 */
class OrganisationTaxAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private function makeTaxpayer(string $vatNumber): Taxpayer
    {
        return Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
    }

    public function test_the_namra_reference_rows_exist_from_the_migration_alone_with_no_seeder_run(): void
    {
        $this->assertDatabaseHas('countries', ['code' => 'NA', 'name' => 'Namibia', 'currency_code' => 'NAD']);
        $this->assertDatabaseHas('tax_jurisdictions', ['id' => 'tax-jurisdiction-na-national', 'country_code' => 'NA']);
        $this->assertDatabaseHas('tax_authorities', ['id' => 'tax-authority-na-namra', 'code' => 'NAMRA', 'jurisdiction_id' => 'tax-jurisdiction-na-national']);
    }

    public function test_an_organisation_created_without_naming_a_tax_authority_defaults_to_namra(): void
    {
        $taxpayer = $this->makeTaxpayer('VAT-TA-0001');

        $organisation = Organisation::create(['id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE']);

        $this->assertSame('tax-authority-na-namra', $organisation->fresh()->tax_authority_id);
        $this->assertDatabaseHas('organisations', ['id' => $organisation->id, 'tax_authority_id' => 'tax-authority-na-namra']);
    }

    /**
     * TaxAuthority::create() is not used here to set up the fixture --
     * see App\Models\TaxAuthority's own doc comment: it deliberately
     * omits HasUuids since no real command in this module creates a row
     * through it, so its Eloquent PK handling defaults to auto-increment
     * and silently mishandles a custom string id. DB::table()->insert()
     * matches how this migration's own reference rows are written.
     */
    public function test_an_organisation_can_be_explicitly_assigned_a_different_tax_authority(): void
    {
        $taxpayer = $this->makeTaxpayer('VAT-TA-0002');
        DB::table('tax_authorities')->insert(['id' => 'tax-authority-test-other', 'jurisdiction_id' => 'tax-jurisdiction-na-national', 'code' => 'OTHER', 'name' => 'Other Test Authority', 'status' => 'ACTIVE', 'created_at' => now()]);

        $organisation = Organisation::create(['id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'tax_authority_id' => 'tax-authority-test-other', 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE']);

        $this->assertSame('tax-authority-test-other', $organisation->fresh()->tax_authority_id);
    }

    /**
     * ->fresh() is required here, not just tidiness: create()'s own
     * in-memory instance only reflects attributes it was actually given
     * (plus the generated primary key) -- a column left out entirely so
     * the database's own DEFAULT applies (see the migration's own doc
     * comment) is never re-read back from MySQL by Eloquent afterwards,
     * so the in-memory object's tax_authority_id stays null until
     * re-fetched, even though the real row already has it set.
     */
    public function test_the_eloquent_relationship_resolves_both_ways(): void
    {
        $taxpayer = $this->makeTaxpayer('VAT-TA-0003');
        $organisation = Organisation::create(['id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE'])->fresh();

        $this->assertSame('NAMRA', $organisation->taxAuthority->code);

        $namra = TaxAuthority::find('tax-authority-na-namra');
        $this->assertTrue($namra->organisations->contains('id', $organisation->id));
    }

    /**
     * Multi-tenant SaaS pivot phase 3 (2026-09-24): found while building
     * phase 3's own TaxJurisdiction lookups. TaxAuthority didn't set
     * `$incrementing = false`/`$keyType = 'string'` (see the model's own
     * doc comment for the mechanism), so Eloquent implicitly cast every
     * read of its own `id` to int -- `(int) 'tax-authority-na-namra'` =
     * 0. test_the_eloquent_relationship_resolves_both_ways() above never
     * caught this: it only reads ->code off a belongsTo result (never
     * touches TaxAuthority's own id) and its ->organisations containment
     * check happened to still pass, because MySQL's loose string-to-int
     * comparison coerces every non-numeric tax_authority_id value to 0
     * too, so `WHERE tax_authority_id = 0` matched every row rather than
     * none -- an accidental over-broad match, not a real pass. This
     * asserts the id itself, which the coercion bug would fail outright.
     */
    public function test_a_tax_authoritys_own_primary_key_reads_back_as_the_real_string_id_not_zero(): void
    {
        $namra = TaxAuthority::find('tax-authority-na-namra');

        $this->assertSame('tax-authority-na-namra', $namra->id);
        $this->assertIsString($namra->id);
    }

    public function test_an_unknown_tax_authority_id_is_rejected_by_the_foreign_key(): void
    {
        $taxpayer = $this->makeTaxpayer('VAT-TA-0004');

        $this->expectException(QueryException::class);
        Organisation::create(['id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'tax_authority_id' => 'tax-authority-does-not-exist', 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE']);
    }
}
