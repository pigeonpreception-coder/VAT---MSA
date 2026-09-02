<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `notifications` table -- the shared
 * notification-creation path (App\Support\Compliance\NotificationRecorder,
 * ported from the source's own notificationRecord) every command in this
 * phase's slice (case opened, dispute filed, obligation created, risk
 * escalated to case) writes through, targeting a taxpayer broadly
 * (user_id NULL) rather than one user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->foreignUuid('taxpayer_id')->nullable()->constrained('taxpayers');
            $table->string('notification_type', 60);
            $table->string('title');
            $table->text('message');
            $table->string('severity', 10);
            $table->string('status', 20);
            $table->string('action_url')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('read_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
