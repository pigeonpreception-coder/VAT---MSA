<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `workflow_versions` table -- one immutable,
 * hashed snapshot of a workflow's node/transition graph per version.
 * `definition` stores the same canonical (sorted-key) JSON its own
 * `definition_hash` was computed from (`AuditService::canonicalJson()`,
 * matching the source's `stableStringify`), so a later re-hash always
 * reproduces the stored hash exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_id')->constrained('workflows');
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->unsignedInteger('version_number');
            $table->string('status', 20);
            $table->string('definition_hash', 64);
            $table->text('definition');
            $table->timestamp('effective_from')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['workflow_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_versions');
    }
};
