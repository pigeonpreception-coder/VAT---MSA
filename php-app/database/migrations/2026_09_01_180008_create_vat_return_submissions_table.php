<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `vat_return_submissions` table. `request_reference`
 * is deterministic per return version (`vat-return:{id}:v{version}`), so
 * VatLifecycleService::submitReturn UPDATEs an existing BLOCKED_CONFIGURATION
 * attempt in place (incrementing attempt_count) rather than re-INSERTing --
 * see that method's own doc comment for the "retry after ITAS might be
 * configured" scenario this UNIQUE(provider, request_reference) constraint
 * would otherwise turn into a raw 500 on retry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_return_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vat_return_version_id')->constrained('vat_return_versions');
            $table->string('provider', 20);
            $table->string('request_reference');
            $table->string('status', 30);
            $table->string('request_hash', 64);
            $table->string('provider_reference')->nullable();
            $table->string('response_hash', 64)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->foreignUuid('requested_by')->constrained('users');
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('last_error')->nullable();

            $table->unique(['provider', 'request_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_return_submissions');
    }
};
