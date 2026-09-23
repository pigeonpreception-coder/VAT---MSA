<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `identity_mismatch_cases` table -- one row per identity_proofing_cases record flagged MISMATCH. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_mismatch_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('proofing_case_id')->unique()->constrained('identity_proofing_cases');
            $table->string('mismatch_type');
            $table->text('conflicting_fields');
            $table->string('details_hash', 64);
            $table->enum('status', ['OPEN', 'RESOLVED', 'REJECTED']);
            $table->string('resolution_code')->nullable();
            $table->foreignUuid('assigned_to')->nullable()->constrained('users');
            $table->foreignUuid('resolved_by')->nullable()->constrained('users');
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_mismatch_cases');
    }
};
