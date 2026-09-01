<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `taxpayer_identifiers` table. Statutory
 * identifiers are versioned, never overwritten in place -- a correction
 * supersedes the current row via previous_version_id rather than an UPDATE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxpayer_identifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('taxpayer_id')->constrained('taxpayers');
            $table->string('identifier_type', 30);
            $table->string('identifier_value', 60);
            $table->string('country', 3)->default('NA');
            $table->string('status', 20);
            $table->string('source', 30);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_to')->nullable();
            $table->uuid('previous_version_id')->nullable();
            $table->foreign('previous_version_id')->references('id')->on('taxpayer_identifiers');

            // Explicit short name -- Laravel's auto-generated name exceeds MySQL's 64-char identifier limit.
            $table->unique(['identifier_type', 'identifier_value', 'country'], 'taxpayer_identifiers_type_value_country_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxpayer_identifiers');
    }
};
