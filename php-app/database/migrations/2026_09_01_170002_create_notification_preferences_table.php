<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `notification_preferences` table -- self-service, keyed by (user_id, channel); IN_APP is accepted but has no enforcement point (see App\Support\Compliance\NotificationRecorder's own doc comment). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('channel', 20);
            $table->boolean('enabled');
            $table->timestamp('updated_at');

            $table->unique(['user_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
