<?php

namespace App\Support\Migration;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 14: the legacy Cloudflare D1 -> MySQL cutover importer.
 *
 * This migration's own design decision to keep UUID `TEXT` primary keys
 * throughout (see docs/MIGRATION_MATRIX.md's "Design decisions carried
 * through the whole migration") means a real cutover is a straight,
 * structural table-by-table row copy, not an FK-remapping exercise:
 * every id, and every FK referencing one, is byte-identical between the
 * legacy D1 export and this MySQL schema.
 *
 * There is no real legacy dataset anywhere in this repository to import
 * -- the original Cloudflare D1 database was never checked into source
 * control, and the only D1-shaped data present locally is
 * `db/runtime.ts`'s own hardcoded demo/seed `INSERT OR IGNORE`
 * statements, which this migration's `php-app/database/seeders/
 * DemoSeeder.php` (plus RoleSeeder/PermissionSeeder/etc.) already ports
 * in full. This class exists as a real, generic, reusable tool for
 * whenever an actual production cutover happens, not a one-off script
 * for data that doesn't exist yet -- verified in this migration against
 * a small synthetic fixture SQLite file built to the same shape (see
 * tests/Feature/Console/LegacyD1ImportTest.php), not real legacy rows.
 *
 * Mechanics: for every table in the source SQLite file, present in this
 * MySQL schema too, every column present in both is copied; a source
 * column with no matching MySQL column is skipped (reported, not
 * silently dropped) -- this is how `positions` (never built -- the
 * source itself never writes to it either) and any other schema-only
 * table stay harmless if present empty in an export. Values are cast
 * per the MySQL column's own `information_schema` type: a
 * timestamp/date column is reparsed (D1/SQLite's ISO-8601 strings like
 * `2026-08-10T08:30:00Z` are not valid `TIMESTAMP`/`DATE` literals);
 * everything else is copied verbatim, including enum columns -- an
 * enum value the MySQL column's own definition doesn't allow is a real
 * fidelity problem this importer deliberately does NOT paper over.
 * Writes use `INSERT IGNORE`, mirroring the source's own `INSERT OR
 * IGNORE` seed convention exactly, so a rerun against the same export
 * is safely idempotent. Foreign-key checks are disabled for the
 * duration of the run (a standard bulk-load technique) since table
 * order in a D1 export is not guaranteed to be FK-safe; this importer
 * does not itself verify referential integrity afterward -- a real
 * cutover should follow it with the target system's own real read
 * paths (this migration's already-verified reports/snapshots) as the
 * actual reconciliation check, not a bespoke integrity-scanning feature
 * built here against data that cannot be tested.
 */
class LegacyD1Importer
{
    private const LEGACY_CONNECTION = 'legacy_d1_import';

    /** D1 table name => MySQL table name, for the one documented merge (the identity-core migration's own doc comment: `app_users` onto Laravel's native `users`). */
    private const RENAMED_TABLES = [
        'app_users' => 'users',
    ];

    /** MySQL table => [MySQL column => D1 column], for columns whose name changed as part of a table merge. */
    private const RENAMED_COLUMNS = [
        'users' => ['name' => 'display_name'],
    ];

    /** Framework-owned tables that must never be touched by this importer, even if a same-named table happened to exist in an export. */
    private const NEVER_IMPORT = [
        'migrations', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    ];

    /** MySQL table => [MySQL column => default-value factory(mappedRow)], for Laravel-only columns a D1 row never carries. */
    private array $requiredDefaults;

    public function __construct()
    {
        $this->requiredDefaults = [
            'users' => [
                // Local Laravel auth must work independently of identity_links (see the identity-core migration's
                // own doc comment) -- a real cutover pairs this with a genuine password-reset/invite flow, the same
                // documented follow-up App\Services\Platform\PlatformChangeService::provisionStaff() already notes.
                'password' => fn () => Hash::make(Str::random(40)),
                'email_verified_at' => fn () => null,
                'remember_token' => fn () => null,
                'updated_at' => fn (array $row) => $row['created_at'] ?? now(),
            ],
        ];
    }

    /**
     * @param  list<string>|null  $only  restrict the import to these MySQL table names
     * @return array{tables: list<array{table: string, source: string, rows: int, mapped: int, written: int, skipped_columns: list<string>}>, total_rows: int}
     */
    public function run(string $sqlitePath, bool $dryRun = false, ?array $only = null): array
    {
        if (! is_file($sqlitePath)) {
            throw new \RuntimeException("Legacy D1 export not found: {$sqlitePath}");
        }

        config(['database.connections.'.self::LEGACY_CONNECTION => [
            'driver' => 'sqlite', 'database' => $sqlitePath, 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge(self::LEGACY_CONNECTION);

        $sourceTables = collect(DB::connection(self::LEGACY_CONNECTION)
            ->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"))
            ->pluck('name')->all();

        $report = ['tables' => [], 'total_rows' => 0];

        if (! $dryRun) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }
        try {
            foreach ($sourceTables as $sourceTable) {
                $mysqlTable = self::RENAMED_TABLES[$sourceTable] ?? $sourceTable;
                if (in_array($mysqlTable, self::NEVER_IMPORT, true)) {
                    continue;
                }
                if (! Schema::hasTable($mysqlTable)) {
                    continue;
                }
                if ($only !== null && ! in_array($mysqlTable, $only, true)) {
                    continue;
                }
                $result = $this->importTable($sourceTable, $mysqlTable, $dryRun);
                $report['tables'][] = $result;
                $report['total_rows'] += $result['rows'];
            }
        } finally {
            if (! $dryRun) {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        return $report;
    }

    /** @return array{table: string, source: string, rows: int, mapped: int, written: int, skipped_columns: list<string>} */
    private function importTable(string $sourceTable, string $mysqlTable, bool $dryRun): array
    {
        $rows = DB::connection(self::LEGACY_CONNECTION)->table($sourceTable)->get();
        /** @var Collection<string, array{name: string, type_name: string}> $columnMeta */
        $columnMeta = collect(Schema::getColumns($mysqlTable))->keyBy('name');
        $renameMap = self::RENAMED_COLUMNS[$mysqlTable] ?? [];
        $defaults = $this->requiredDefaults[$mysqlTable] ?? [];

        $skippedColumns = [];
        $prepared = [];
        foreach ($rows as $row) {
            $mapped = [];
            foreach ((array) $row as $sourceColumn => $value) {
                $mysqlColumn = array_search($sourceColumn, $renameMap, true) ?: $sourceColumn;
                if (! $columnMeta->has($mysqlColumn)) {
                    $skippedColumns[$sourceColumn] = true;

                    continue;
                }
                $mapped[$mysqlColumn] = $this->castValue($value, $columnMeta[$mysqlColumn]['type_name']);
            }
            foreach ($defaults as $column => $factory) {
                if (! array_key_exists($column, $mapped)) {
                    $mapped[$column] = $factory($mapped);
                }
            }
            $prepared[] = $mapped;
        }

        if (! $dryRun && count($prepared) > 0) {
            foreach (array_chunk($prepared, 500) as $chunk) {
                DB::table($mysqlTable)->insertOrIgnore($chunk);
            }
        }

        return [
            'table' => $mysqlTable, 'source' => $sourceTable, 'rows' => count($rows),
            'mapped' => count($prepared), 'written' => $dryRun ? 0 : count($prepared),
            'skipped_columns' => array_keys($skippedColumns),
        ];
    }

    private function castValue(mixed $value, string $mysqlType): mixed
    {
        if ($value === null) {
            return null;
        }

        return match (true) {
            in_array($mysqlType, ['timestamp', 'datetime', 'date'], true) && is_string($value) && $value !== ''
                => Carbon::parse($value)->format($mysqlType === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s'),
            $mysqlType === 'tinyint' => (int) $value,
            default => $value,
        };
    }
}
