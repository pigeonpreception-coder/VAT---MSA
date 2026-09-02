<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `api_clients` table -- Module 10's
 * Developer Portal, the anchor `credential_refs`/`test_runs`/
 * `webhook_subscriptions` build on. `developer_account_id` is get-or-
 * created by CreateClient rather than a separate command, per the
 * source's own comment. No command references this table yet in this
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('developer_account_id')->nullable()->constrained('developer_accounts');
            $table->string('name');
            $table->string('client_key', 100)->unique();
            $table->text('scopes');
            $table->string('credential_reference');
            $table->string('status', 20);
            $table->string('rate_limit_profile', 30);
            $table->timestamp('last_rotated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_clients');
    }
};
