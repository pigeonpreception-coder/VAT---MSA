<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `bank_imports` table -- the source's own
 * platform snapshot reads it (`getPlatformSnapshot`, not yet ported). No
 * command references this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('integration_connection_id')->nullable()->constrained('integration_connections');
            $table->foreignUuid('document_id')->nullable()->constrained('document_metadata');
            $table->string('bank_name');
            $table->string('account_reference_masked');
            $table->date('statement_from');
            $table->date('statement_to');
            $table->string('currency', 3);
            $table->unsignedInteger('transaction_count')->default(0);
            $table->string('status', 20);
            $table->foreignUuid('requested_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_imports');
    }
};
