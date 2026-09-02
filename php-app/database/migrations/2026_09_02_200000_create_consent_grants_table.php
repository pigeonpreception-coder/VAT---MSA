<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `consent_grants` table. Verified via a
 * full-repo grep before writing this migration: no command anywhere in
 * the TypeScript source ever writes to this table (only the demo seed
 * data does, via a bare `INSERT OR IGNORE`) -- `getComplianceSnapshot` is
 * its only reader. Built purely to let that snapshot aggregate read the
 * same shape the source does, not because a GrantConsent command exists
 * to port.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('taxpayer_id')->constrained('taxpayers');
            $table->foreignUuid('granted_by')->constrained('users');
            $table->string('grantee_type', 20);
            $table->string('grantee_id', 100);
            $table->string('purpose');
            $table->text('data_categories');
            $table->string('legal_basis', 40);
            $table->string('status', 20);
            $table->timestamp('valid_from');
            $table->timestamp('valid_to')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_grants');
    }
};
