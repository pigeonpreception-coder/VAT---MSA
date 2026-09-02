<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `credential_refs` table -- the rotation/
 * revocation history behind an `api_clients` row's own
 * `credential_reference` column. No command references this table yet in
 * this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_refs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('api_client_id')->constrained('api_clients');
            $table->string('credential_reference');
            $table->string('status', 20);
            $table->foreignUuid('issued_by')->constrained('users');
            $table->timestamp('issued_at')->useCurrent();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users');
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_refs');
    }
};
