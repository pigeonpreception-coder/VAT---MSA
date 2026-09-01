<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `audit_case_notes` table -- append-only; a correction is a fresh note carrying supersedes_note_id, never an UPDATE. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_case_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('audit_case_id')->constrained('audit_cases');
            $table->foreignUuid('author_id')->constrained('users');
            $table->text('body');
            $table->uuid('supersedes_note_id')->nullable();
            $table->foreign('supersedes_note_id')->references('id')->on('audit_case_notes');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_case_notes');
    }
};
