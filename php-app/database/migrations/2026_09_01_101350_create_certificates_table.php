<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `certificates` table. `signature` is
 * `DEV.{sha256}` unkeyed content-digest signing (`signature_profile`
 * 'DEV-SHA256') -- the source is explicit this is integrity, not a real
 * cryptographic signature/non-repudiation, pending an out-of-scope HSM/KMS
 * decision (SECURITY_GAP_ASSESSMENT.md domains 6/7). Kept exactly as-is,
 * not upgraded, since no keyed signing capability exists in the source to
 * port.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->unique()->constrained('invoices');
            $table->string('verification_token')->unique();
            $table->string('invoice_hash', 64);
            $table->string('signature');
            $table->string('signature_profile', 20);
            $table->string('status', 20);
            $table->timestamp('issued_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
