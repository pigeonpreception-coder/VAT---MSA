<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `audit_events` table -- the single canonical
 * hash-chained audit writer's target (see App\Services\Audit\AuditService,
 * the port of lib/data/audit-repository.ts's appendAuditEvent). actor_id
 * deliberately carries no FK in the source (an actor who is later deleted
 * must not silently break historical audit rows) -- kept that way here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_id');
            $table->string('actor_role', 40);
            $table->string('action', 80);
            $table->string('resource_type', 40);
            $table->string('resource_id');
            $table->string('outcome', 20);
            $table->longText('details');
            $table->string('previous_hash', 64)->nullable();
            $table->string('event_hash', 64);
            $table->timestamp('occurred_at');

            $table->index(['resource_type', 'resource_id']);
            $table->index('actor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
