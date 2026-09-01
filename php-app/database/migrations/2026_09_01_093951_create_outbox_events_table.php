<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `outbox_events` table (transactional outbox pattern -- no queue/cron infra in the source's Workers deployment, matched here rather than assuming Laravel's queue changes that). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('aggregate_type', 40);
            $table->string('aggregate_id');
            $table->string('event_type', 80);
            $table->unsignedInteger('event_version');
            $table->string('partition_key');
            $table->longText('payload');
            $table->string('status', 20);
            $table->unsignedInteger('publish_attempts')->default(0);
            // ->useCurrent() satisfies MariaDB strict-mode's "only one TIMESTAMP NOT NULL column
            // may lack an explicit default" rule -- application code always sets both explicitly
            // on insert regardless (see AuditService/RegistrationService), matching the source's
            // own NOT NULL, always-populated intent.
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('published_at')->nullable();
            $table->text('last_error')->nullable();

            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
