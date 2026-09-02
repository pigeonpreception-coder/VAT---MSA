<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `seed_state` table -- the source's own
 * marker table recording which seed statements have already run (its
 * `key` column is a plain string primary key, not a uuid). This
 * migration's own seed idempotency is handled by Laravel's `updateOrCreate`
 * seeders instead, so nothing writes to this table yet -- it exists purely
 * for schema parity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seed_state', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->timestamp('applied_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seed_state');
    }
};
