<?php

namespace Tests\Feature\Console;

use App\Support\Migration\LegacyD1Importer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers App\Support\Migration\LegacyD1Importer (Phase 14's legacy D1 ->
 * MySQL cutover tool). There is no real legacy dataset anywhere in this
 * repository (see the importer's own doc comment) -- this test builds a
 * small synthetic SQLite fixture, in the same shape a real `wrangler d1
 * export` would have, and proves the generic mechanism: table/column
 * discovery, the one documented rename (app_users -> users,
 * display_name -> name), required-default injection for Laravel-only
 * columns, timestamp/date reformatting, skip-and-report for an unmapped
 * column, skip-and-continue for a table this schema never built
 * (`positions`), dry-run (no writes), and idempotent reruns via
 * INSERT IGNORE.
 */
class LegacyD1ImportTest extends TestCase
{
    use RefreshDatabase;

    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = tempnam(sys_get_temp_dir(), 'd1fixture').'.sqlite';
        @unlink($this->fixturePath);
        $this->buildFixture($this->fixturePath);
    }

    protected function tearDown(): void
    {
        @unlink($this->fixturePath);
        parent::tearDown();
    }

    /** Builds a standalone SQLite file via raw PDO -- deliberately not through Laravel's own schema builder, to stand in for a real, independently-produced `wrangler d1 export` file. */
    private function buildFixture(string $path): void
    {
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('PRAGMA foreign_keys = OFF');

        // taxpayers: a real column set, plus one column ("legacy_note") this MySQL schema has
        // no counterpart for -- proves skip-and-report, not skip-and-fail.
        $pdo->exec('CREATE TABLE taxpayers (
            id TEXT PRIMARY KEY, vat_number TEXT, tin TEXT, legal_name TEXT, trading_name TEXT,
            taxpayer_type TEXT, vat_status TEXT, return_frequency TEXT, address TEXT, email TEXT,
            created_at TEXT, legacy_note TEXT
        )');
        $pdo->prepare('INSERT INTO taxpayers (id, vat_number, tin, legal_name, trading_name, taxpayer_type, vat_status, return_frequency, address, email, created_at, legacy_note)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
                'tp-legacy-0001', 'VAT-LEGACY-0001', 'TIN-LEGACY-0001', 'Legacy Trading Co', null,
                'PRIVATE_COMPANY', 'ACTIVE', 'MONTHLY', '1 Legacy Street, Windhoek', 'legacy@d1test.test',
                '2026-08-10T08:30:00Z', 'imported from the old system',
            ]);

        $pdo->exec('CREATE TABLE organisations (
            id TEXT PRIMARY KEY, taxpayer_id TEXT, legal_name TEXT, trading_name TEXT, status TEXT,
            created_at TEXT, updated_at TEXT
        )');
        $pdo->prepare('INSERT INTO organisations (id, taxpayer_id, legal_name, trading_name, status, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?)')->execute([
                'org-legacy-0001', 'tp-legacy-0001', 'Legacy Trading Co', null, 'ACTIVE',
                '2026-08-10T08:31:00Z', '2026-08-10T08:31:00Z',
            ]);

        // app_users: the one documented table rename (-> users) and column rename
        // (display_name -> name); has none of Laravel's own password/email_verified_at/
        // remember_token/updated_at columns, proving required-default injection.
        $pdo->exec('CREATE TABLE app_users (
            id TEXT PRIMARY KEY, external_user_id TEXT, email TEXT, display_name TEXT, role TEXT,
            taxpayer_id TEXT, status TEXT, created_at TEXT
        )');
        $pdo->prepare('INSERT INTO app_users (id, external_user_id, email, display_name, role, taxpayer_id, status, created_at)
            VALUES (?,?,?,?,?,?,?,?)')->execute([
                'usr-legacy-0001', 'ext-legacy-0001', 'owner-legacy@d1test.test', 'Legacy Owner', 'TAXPAYER_OWNER',
                'tp-legacy-0001', 'ACTIVE', '2026-08-10T08:32:00Z',
            ]);

        // positions: schema-only in this migration (never built -- the source itself never
        // writes to it either); present in the export but must be skipped harmlessly.
        $pdo->exec('CREATE TABLE positions (id TEXT PRIMARY KEY, title TEXT)');
        $pdo->prepare('INSERT INTO positions (id, title) VALUES (?,?)')->execute(['pos-legacy-0001', 'Never built']);

        $pdo = null;
    }

    public function test_dry_run_reports_without_writing_anything(): void
    {
        $report = (new LegacyD1Importer)->run($this->fixturePath, dryRun: true);

        $byTable = collect($report['tables'])->keyBy('table');
        $this->assertSame(1, $byTable['taxpayers']['rows']);
        $this->assertSame(0, $byTable['taxpayers']['written']);
        $this->assertContains('legacy_note', $byTable['taxpayers']['skipped_columns']);
        $this->assertSame('app_users', $byTable['users']['source']);
        $this->assertArrayNotHasKey('positions', $byTable->all());

        $this->assertDatabaseCount('taxpayers', 0);
        $this->assertDatabaseCount('organisations', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_real_run_imports_rows_with_the_documented_rename_and_type_casting(): void
    {
        $report = (new LegacyD1Importer)->run($this->fixturePath, dryRun: false);

        $this->assertGreaterThan(0, $report['total_rows']);

        $taxpayer = DB::table('taxpayers')->where('id', 'tp-legacy-0001')->first();
        $this->assertNotNull($taxpayer);
        $this->assertSame('VAT-LEGACY-0001', $taxpayer->vat_number);
        // ISO-8601 'Z' reformatted into a MySQL-native TIMESTAMP literal.
        $this->assertSame('2026-08-10 08:30:00', $taxpayer->created_at);

        $organisation = DB::table('organisations')->where('id', 'org-legacy-0001')->first();
        $this->assertNotNull($organisation);
        $this->assertSame('tp-legacy-0001', $organisation->taxpayer_id);

        $user = DB::table('users')->where('id', 'usr-legacy-0001')->first();
        $this->assertNotNull($user);
        $this->assertSame('ext-legacy-0001', $user->external_user_id);
        // display_name -> name (the documented column rename).
        $this->assertSame('Legacy Owner', $user->name);
        // Required Laravel-only defaults, never present in the D1 row.
        $this->assertNotNull($user->password);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('2026-08-10 08:32:00', $user->updated_at);

        // positions was never written to (the table itself is not part of this schema).
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('positions'));
    }

    public function test_rerunning_against_the_same_export_is_idempotent(): void
    {
        $importer = new LegacyD1Importer;
        $importer->run($this->fixturePath, dryRun: false);
        $importer->run($this->fixturePath, dryRun: false);

        $this->assertDatabaseCount('taxpayers', 1);
        $this->assertDatabaseCount('organisations', 1);
        $this->assertSame(1, DB::table('users')->where('id', 'usr-legacy-0001')->count());
    }

    public function test_the_only_option_restricts_the_import_to_named_tables(): void
    {
        (new LegacyD1Importer)->run($this->fixturePath, dryRun: false, only: ['taxpayers']);

        $this->assertDatabaseCount('taxpayers', 1);
        $this->assertDatabaseCount('organisations', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_an_unreadable_export_path_fails_clearly(): void
    {
        $this->expectException(\RuntimeException::class);

        (new LegacyD1Importer)->run('/no/such/file.sqlite');
    }

    public function test_the_console_command_runs_end_to_end_with_a_confirmation_prompt(): void
    {
        $this->artisan('legacy:import-d1', ['path' => $this->fixturePath, '--only' => 'taxpayers'])
            ->expectsConfirmation('This writes into the database configured in .env (DB_DATABASE='.config('database.connections.mysql.database').'). Continue?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseCount('taxpayers', 1);
    }

    public function test_the_console_command_dry_run_skips_the_confirmation_prompt_and_writes_nothing(): void
    {
        $this->artisan('legacy:import-d1', ['path' => $this->fixturePath, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('taxpayers', 0);
    }
}
