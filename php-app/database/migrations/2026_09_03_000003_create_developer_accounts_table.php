<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `developer_accounts` table -- Module 10's
 * Developer Portal (control-plane-repository.ts), the one sub-domain of
 * that file Phase 12 deliberately deferred (see "Licensing & Entitlements"
 * in docs/MIGRATION_MATRIX.md). No command references this table yet in
 * this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('developer_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('owner_user_id')->constrained('users');
            $table->string('display_name');
            $table->string('status', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['organisation_id', 'owner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('developer_accounts');
    }
};
