<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A genuine bug in this migration's own earlier work, caught while wiring
 * the Inventory (POS) module's checkout to StockMovement::recordMovement:
 * `stock_movements.reference_id` was typed `uuid()` (CHAR(36)) in the
 * original Phase 10 migration, but db/schema.ts's own `stockMovements`
 * table declares `reference_id` as plain TEXT with no UUID shape
 * constraint, and lib/data/business-repository.ts's recordStockMovement
 * never validates it as a UUID either. The source's own POS checkout flow
 * (app/operations/inventory/PosTerminal.tsx) genuinely writes a composite
 * value here -- `${invoiceId}:${lineNumber}` -- to keep each cart line's
 * movement distinct under the ux_stock_movement_reference unique index,
 * which is longer than 36 characters and would be silently truncated (or
 * rejected outright under strict SQL mode) by the narrower CHAR(36)
 * column. Widened to VARCHAR(100) here, consistent with this migration's
 * documented convention (widen rather than invent a narrower constraint
 * the source never had -- see MIGRATION_MATRIX.md's "widen VAT
 * transaction type" entry for the same fix applied earlier to a different
 * table).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE stock_movements MODIFY reference_id VARCHAR(100) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_movements MODIFY reference_id CHAR(36) NOT NULL');
    }
};
