<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fixes a real, discovered defect ahead of Module 8 Phase D's
 * RunAuditChainVerification: `audit_events.occurred_at` was created as a
 * plain `TIMESTAMP` (whole-second precision), but
 * App\Services\Audit\AuditService::write() hashes an in-memory
 * DateTimeInterface formatted to microsecond precision (`isoMicro()`) --
 * so MySQL silently truncated every stored `occurred_at` value, and
 * re-deriving a row's hash from what was actually persisted could never
 * reproduce the hash computed at write time, for ANY row, tampered or
 * not. Widening the column to `TIMESTAMP(6)` makes future writes
 * round-trip exactly (paired with `AuditEvent::$dateFormat` now also
 * serialising microseconds -- Eloquent's default MySQL date format
 * otherwise truncates to whole seconds regardless of the column's own
 * precision). It cannot recover the already-discarded microseconds of
 * rows written before this fix -- see docs/MIGRATION_MATRIX.md's own
 * dated section on this for the disclosed, unavoidable consequence for
 * this port's own pre-existing audit history.
 *
 * Uses a raw ALTER TABLE rather than Schema::table()->change(), since
 * doctrine/dbal is not installed in this vendor tree.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE audit_events MODIFY occurred_at TIMESTAMP(6) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE audit_events MODIFY occurred_at TIMESTAMP NOT NULL');
    }
};
