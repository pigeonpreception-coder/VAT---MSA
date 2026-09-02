<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `webhook_deliveries` table -- one delivery
 * attempt of an `outbox_events` row to a `webhook_subscriptions`
 * endpoint. No command references this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('webhook_subscription_id')->constrained('webhook_subscriptions');
            $table->foreignUuid('outbox_event_id')->constrained('outbox_events');
            $table->string('status', 20);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['webhook_subscription_id', 'outbox_event_id'], 'webhook_deliveries_subscription_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
