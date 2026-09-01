<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `taxpayers` table (VAT-MSA TypeScript/D1 source).
 * UUID primary keys throughout this migration set (not auto-increment) to
 * keep 1:1 ID preservation trivial for the Phase 14 legacy importer, since
 * every FK across the original 155-table D1 schema references a TEXT UUID.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxpayers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('vat_number')->unique();
            $table->string('tin');
            $table->string('legal_name');
            $table->string('trading_name')->nullable();
            // Enum sets verified against lib/domain/identity.ts's TAXPAYER_TYPES/RETURN_FREQUENCIES
            // and grep-confirmed vat_status literals (only ACTIVE/SUSPENDED ever appear in the
            // source -- no PENDING/DEREGISTERED lifecycle exists there, so none is invented here).
            $table->enum('taxpayer_type', ['PRIVATE_COMPANY', 'CLOSE_CORPORATION', 'SOLE_PROPRIETOR', 'PARTNERSHIP', 'TRUST', 'NON_PROFIT', 'PUBLIC_ENTITY', 'OTHER']);
            $table->enum('vat_status', ['ACTIVE', 'SUSPENDED']);
            $table->enum('return_frequency', ['MONTHLY', 'BIMONTHLY', 'QUARTERLY', 'ANNUAL']);
            $table->text('address');
            $table->string('email');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxpayers');
    }
};
