<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fixes a real, discovered defect ahead of Module 10 Phase D's CreateClient:
 * `api_clients.status` was created as `VARCHAR(20)` -- wide enough for
 * every status this port's own App\Services\Integration\PosApiClientService
 * ever wrote ('ACTIVE'/'REVOKED'), but source's own createClient writes
 * 'PENDING_CREDENTIAL_PROVISIONING' (31 characters) as the status every
 * freshly-created client starts in (see lib/data/developer-repository.ts's
 * own createClient and lib/domain/developer.ts's own comment on why that
 * status is permanent in this environment). MySQL's strict sql_mode
 * (config/database.php's 'strict' => true) turns a value too long for the
 * column into a hard error rather than silent truncation, so the very
 * first CreateClient in any environment failed outright until this widened
 * the column -- caught empirically via this port's own feature test suite,
 * not by inspection, the same way the audit_events occurred_at precision
 * defect was (2026_09_22_000001_widen_audit_events_occurred_at_precision.php).
 *
 * Uses a raw ALTER TABLE rather than Schema::table()->change(), since
 * doctrine/dbal is not installed in this vendor tree.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE api_clients MODIFY status VARCHAR(40) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE api_clients MODIFY status VARCHAR(20) NOT NULL');
    }
};
