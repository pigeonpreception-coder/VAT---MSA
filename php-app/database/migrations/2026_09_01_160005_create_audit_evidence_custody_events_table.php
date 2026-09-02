<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `audit_evidence_custody_events` table -- an append-only custody log (ADDED/VERIFY/SET_LEGAL_HOLD/RELEASE_LEGAL_HOLD/SUPERSEDED). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_evidence_custody_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('audit_evidence_id')->constrained('audit_evidence');
            $table->string('action', 20);
            $table->foreignUuid('actor_id')->constrained('users');
            $table->text('notes')->nullable();
            $table->boolean('integrity_verified')->nullable();
            $table->timestamp('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_evidence_custody_events');
    }
};
