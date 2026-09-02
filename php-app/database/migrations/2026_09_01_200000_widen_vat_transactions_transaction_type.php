<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A genuine bug in this migration's own earlier work, caught while porting
 * cancelInvoice: `vat_transactions.transaction_type` was narrowed to
 * `ENUM('CERTIFICATION','CORRECTION')` in the original Phase 9 migration --
 * a real mistake, not a source constraint. db/runtime.ts's own
 * `vat_transactions` schema declares `transaction_type TEXT NOT NULL` with
 * no CHECK constraint at all, and lib/data/repository.ts's cancelInvoice
 * genuinely inserts a third value, `CANCELLATION`, which the narrowed enum
 * would reject outright. Widened to a plain VARCHAR here rather than adding
 * a third enum value, consistent with this migration's own documented
 * convention (see MIGRATION_MATRIX.md's "Design decisions" section) of
 * using VARCHAR rather than ENUM once a value set turns out not to have
 * been exhaustively confirmed up front.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE vat_transactions MODIFY transaction_type VARCHAR(20) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE vat_transactions MODIFY transaction_type ENUM('CERTIFICATION','CORRECTION') NOT NULL");
    }
};
