<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `feature_flags` table -- Module 22's
 * platform-configuration surface. No command references this table yet
 * in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 100)->unique();
            $table->string('name');
            $table->text('description');
            $table->string('rollout_scope', 30);
            $table->boolean('enabled')->default(false);
            $table->string('status', 20);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('created_at')->useCurrent();
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
