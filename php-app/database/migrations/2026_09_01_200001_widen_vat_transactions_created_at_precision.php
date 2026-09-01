<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A genuine bug caught live by this session's own InvoiceLifecycleTest:
 * getTransactionTimeline orders a lineage's events by
 * `vat_transactions.created_at`, and a certification followed quickly by
 * its own correction/cancellation (well within reach in an automated test,
 * and plausible for a fast human retry too) tied under this codebase's
 * usual bare (0-fractional-second) TIMESTAMP, making "chronological order"
 * ambiguous -- the exact same class of bug already found and fixed for
 * `communications.occurred_at` in Phase 11 slice 2 (see that migration's
 * own doc comment). The source's own SQLite TEXT timestamps
 * (`new Date().toISOString()`) carry millisecond precision natively and
 * never hit this. Fixed the same way: microsecond column precision here,
 * plus VatTransaction's own $dateFormat to actually preserve it end to end
 * through Eloquent's serialization (column precision alone is insufficient).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE vat_transactions MODIFY created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE vat_transactions MODIFY created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }
};
