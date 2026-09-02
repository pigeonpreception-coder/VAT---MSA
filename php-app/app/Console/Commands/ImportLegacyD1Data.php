<?php

namespace App\Console\Commands;

use App\Support\Migration\LegacyD1Importer;
use Illuminate\Console\Command;

/**
 * Phase 14: `php artisan legacy:import-d1`. See App\Support\Migration\
 * LegacyD1Importer's own doc comment for the full design -- this class
 * is deliberately a thin CLI wrapper (argument parsing, confirmation
 * prompt, report rendering) around that service, matching this
 * migration's usual controller/service split.
 */
class ImportLegacyD1Data extends Command
{
    protected $signature = 'legacy:import-d1
        {path : Absolute path to a `wrangler d1 export --output=...` SQLite file}
        {--dry-run : Report what would be imported without writing anything}
        {--only= : Comma-separated MySQL table names to restrict the import to}';

    protected $description = 'Import a legacy Cloudflare D1 SQLite export into this MySQL schema (one-time cutover tool)';

    public function handle(LegacyD1Importer $importer): int
    {
        $path = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('only') ? array_map('trim', explode(',', (string) $this->option('only'))) : null;

        if ($dryRun) {
            $this->warn('Dry run -- no rows will be written.');
        } else {
            $database = (string) config('database.connections.mysql.database');
            if (! $this->confirm("This writes into the database configured in .env (DB_DATABASE={$database}). Continue?", false)) {
                $this->info('Aborted.');

                return self::SUCCESS;
            }
        }

        try {
            $report = $importer->run($path, $dryRun, $only);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Table', 'Source', 'Rows', $dryRun ? 'Would write' : 'Written', 'Skipped columns'],
            collect($report['tables'])->map(fn (array $t) => [
                $t['table'], $t['source'], $t['rows'], $dryRun ? $t['mapped'] : $t['written'],
                $t['skipped_columns'] ? implode(', ', $t['skipped_columns']) : '-',
            ])->all()
        );
        $this->info(sprintf(
            '%s %d row%s across %d table%s.',
            $dryRun ? 'Would write' : 'Wrote',
            $report['total_rows'], $report['total_rows'] === 1 ? '' : 's',
            count($report['tables']), count($report['tables']) === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }
}
