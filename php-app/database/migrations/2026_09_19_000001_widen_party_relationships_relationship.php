<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Closes the `registered.service-providers` $plannedRoute placeholder
 * ("Business-party records do not yet carry a service-provider
 * relationship or category"). Rather than add a third enum value,
 * widened to a plain VARCHAR, matching this codebase's own established
 * convention for exactly this situation -- see
 * 2026_09_01_200000_widen_vat_transactions_transaction_type.php's own
 * doc comment ("using VARCHAR rather than ENUM once a value set turns
 * out not to have been exhaustively confirmed up front").
 * `App\Domain\Business\BusinessValidator::PARTY_RELATIONSHIPS` remains
 * the actual application-level allow-list (now `CUSTOMER`/`SUPPLIER`/
 * `SERVICE_PROVIDER`); this migration only removes the database-level
 * constraint that would otherwise reject the third value outright.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE party_relationships MODIFY relationship VARCHAR(20) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE party_relationships MODIFY relationship ENUM('CUSTOMER','SUPPLIER') NOT NULL");
    }
};
