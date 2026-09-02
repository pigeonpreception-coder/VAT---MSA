<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `platform_config` table -- Module 22's
 * platform-configuration surface (a sibling of `feature_flags`/
 * `access_policies`, all three managed by the same not-yet-ported
 * proposeChange/applyChange change-management flow -- see
 * `change_requests`). No command references this table yet in this
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_config', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 100)->unique();
            $table->string('category', 40);
            $table->text('description');
            $table->text('value');
            $table->string('status', 20);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('created_at')->useCurrent();
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_config');
    }
};
