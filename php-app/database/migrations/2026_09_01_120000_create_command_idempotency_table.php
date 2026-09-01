<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `command_idempotency` table -- the generic,
 * reusable idempotency ledger every command in lib/data/business-repository.ts
 * uses (parties, quotations, and -- in later Phase 10 slices -- journals,
 * expenses, inventory, projects), distinct from Phase 9's invoice-specific
 * `idempotency_records` (which predates this generic table in the source
 * and was never migrated onto it there either -- kept equally distinct
 * here, not merged).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_idempotency', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_id')->constrained('users');
            $table->string('command_type', 60);
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->string('resource_type', 40);
            $table->uuid('resource_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['actor_id', 'command_type', 'idempotency_key'], 'command_idempotency_actor_type_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_idempotency');
    }
};
