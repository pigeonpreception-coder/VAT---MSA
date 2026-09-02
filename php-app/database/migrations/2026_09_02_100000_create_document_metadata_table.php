<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `document_metadata` table -- Module 22's own
 * central table, pulled forward as the real prerequisite for closing
 * Phase 11's last gap (`DOCUMENT`-sourced audit evidence citation), the
 * same "unblock the real dependency, don't invent a shortcut" pattern the
 * VAT-return-generation prerequisite and `access_reviews` (pulled forward
 * for Phase 12 slice 2) already established. Only `uploadDocument`'s
 * quarantine INSERT and `completeDocumentScan`'s scan-decision UPDATE are
 * ported alongside this table -- the minimal real chain that gets a
 * document to `scan_status='CLEAN'`, which is the one precondition
 * `AuditCaseService::addEvidence()`'s own DOCUMENT branch requires.
 * `supersedes_document_id` is schema-complete but genuinely has no writer
 * in this slice -- `supersedeDocument` (Module 22's own document
 * versioning command) is out of scope here, squarely Phase 13's job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_metadata', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('owner_domain', 40);
            $table->string('owner_resource_id', 100);
            $table->string('object_key')->unique();
            $table->string('file_name');
            $table->string('content_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum_sha256', 64);
            $table->string('classification', 30);
            $table->string('scan_status', 30);
            $table->string('status', 20);
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamp('retained_until')->nullable();
            $table->boolean('legal_hold')->default(false);
            $table->foreignUuid('scanned_by')->nullable()->constrained('users');
            $table->timestamp('scanned_at')->nullable();
            $table->foreignUuid('supersedes_document_id')->nullable()->constrained('document_metadata');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_metadata');
    }
};
