<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `security_events` table. `actor_id` carries no
 * FK, matching the source (and audit_events -- see that migration's own
 * note): a security event about an actor who no longer exists, or about an
 * anonymous/unauthenticated request, must still be recordable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_type', 60);
            $table->string('severity', 10);
            $table->uuid('actor_id')->nullable();
            $table->string('source_token');
            $table->uuid('correlation_id');
            $table->string('action', 60);
            $table->string('outcome', 20);
            $table->longText('details');
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['severity', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
