<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles a real schema drift, not a new fix: commit 1e50c1f
 * ("Preserve explicit default on audit_events.occurred_at widen
 * migration") edited 2026_09_22_000001_widen_audit_events_occurred_at_
 * precision.php's own `up()` in place to add `DEFAULT CURRENT_TIMESTAMP(6)`
 * -- a bare `MODIFY` otherwise drops any existing default under this
 * app's own enforced strict sql_mode. Editing an already-applied
 * migration file does not re-run it (`migrations` table tracks it as
 * done by filename, same as every other already-migrated-database
 * situation elsewhere in this codebase -- see e.g. 2026_09_13_000003_
 * rename_pilot_admin_role_to_namra_staff.php's own doc comment), so any
 * database that ran 2026_09_22_000001 before 1e50c1f landed (this
 * project's own production database confirmed to be exactly that: its
 * `occurred_at` column is TIMESTAMP(6) NOT NULL with COLUMN_DEFAULT NULL,
 * verified directly against information_schema.columns) is still missing
 * the default and stays that way until a migration with a NEW filename
 * re-issues the ALTER -- this one.
 *
 * Confirmed via a full-repo grep this has caused no actual failure: the
 * only INSERT into audit_events anywhere in this codebase
 * (App\Services\Audit\AuditService::write(), the sole
 * `AuditEvent::create()` call site) always explicitly supplies
 * `occurred_at` itself, so no write has ever relied on the column's own
 * default. This closes the drift defensively, matching what a fresh
 * install now gets, not because anything broke.
 *
 * Safe to run on a fresh install too (a database that never ran the old
 * pre-1e50c1f version, so this would otherwise be a pure duplicate of
 * what 2026_09_22_000001 already just did): MODIFY fully re-specifies
 * the column each time, so reapplying an identical definition is a
 * harmless no-op, not an error.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE audit_events MODIFY occurred_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)');
    }

    public function down(): void
    {
        // Deliberately a no-op, not a revert to no-default: 2026_09_22_000001's
        // own down() already restores the pre-widen column entirely
        // (TIMESTAMP NOT NULL) if that migration is ever rolled back, and
        // this migration should never reintroduce the no-default state
        // that caused the drift this reconciles in the first place.
    }
};
