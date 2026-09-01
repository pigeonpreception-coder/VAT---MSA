<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `communications` table -- one row per
 * message, always attached to a thread by this port's own SendNotice/
 * Respond (the source's `thread_id` predates this phase and is nullable
 * for older, thread-less rows it never wrote either).
 *
 * `occurred_at` uses microsecond precision (`timestamp('occurred_at', 6)`)
 * rather than this codebase's usual bare `timestamp()` -- GetInbox orders
 * each thread's "latest message" by this column, and a second reply
 * arriving within the same wall-clock second as the first would otherwise
 * tie under MySQL's default 0-fractional-second TIMESTAMP precision,
 * making "latest" ambiguous. The source's own SQLite `TEXT` timestamps
 * (`new Date().toISOString()`) carry millisecond precision natively, so
 * this needed an explicit opt-in here that the source's schema gets for
 * free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->nullable()->constrained('organisations');
            $table->foreignUuid('taxpayer_id')->nullable()->constrained('taxpayers');
            $table->foreignUuid('thread_id')->nullable()->constrained('communication_threads');
            $table->string('channel', 20);
            $table->enum('direction', ['INBOUND', 'OUTBOUND']);
            $table->string('subject');
            $table->text('content_summary');
            $table->string('classification', 20);
            $table->string('related_resource_type', 30)->nullable();
            $table->string('related_resource_id')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('status', 20);
            $table->foreignUuid('actor_id')->constrained('users');
            $table->timestamp('occurred_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communications');
    }
};
