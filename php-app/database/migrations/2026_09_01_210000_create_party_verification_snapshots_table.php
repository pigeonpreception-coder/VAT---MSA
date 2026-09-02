<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `party_verification_snapshots` table --
 * Module 5 Phase A VerifySupplier's own append-only record, the last
 * remaining table Phase 10 (accounting/commercial) deferred.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_verification_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('party_id')->constrained('business_parties');
            $table->string('vat_number');
            $table->boolean('taxpayer_active');
            $table->boolean('organisation_active');
            $table->boolean('can_act_as_seller');
            $table->text('capabilities');
            $table->foreignUuid('verified_by')->constrained('users');
            $table->timestamp('verified_at')->useCurrent();

            $table->index(['party_id', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_verification_snapshots');
    }
};
