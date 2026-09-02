<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `webhook_subscriptions` table -- an
 * `api_clients` row's own outbound-event subscription, the anchor
 * `webhook_deliveries` builds on. No command references this table yet
 * in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('api_client_id')->constrained('api_clients');
            $table->text('event_types');
            $table->string('endpoint_url');
            $table->string('signing_key_reference');
            $table->string('status', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['api_client_id', 'endpoint_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_subscriptions');
    }
};
