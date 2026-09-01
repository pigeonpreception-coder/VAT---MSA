<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `audit_evidence` table -- Module 4 Phase D.
 * `document_id` carries no FK here -- the source references
 * `document_metadata(id)`, but Phase 14's documents module has not been
 * migrated yet (see docs/MIGRATION_MATRIX.md); a DOCUMENT-sourced evidence
 * citation is consequently deferred in this phase's port (INVOICE/OTHER
 * only), a documented gap rather than a silent one.
 *
 * The source's own guarantee that only one PRESERVED row may exist per
 * (audit_case_id, source_resource_type, source_resource_id) is a SQLite
 * *partial* unique index (`WHERE status='PRESERVED'`) -- MySQL/MariaDB has
 * no equivalent construct, so this is enforced at the application layer
 * only (App\Services\Compliance\AuditCaseService::addEvidence's own
 * pre-check), not backed by a database-level constraint the way the
 * source's is. A genuine, documented fidelity gap, not an oversight.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('audit_case_id')->constrained('audit_cases');
            $table->string('evidence_type', 30);
            $table->enum('source_resource_type', ['INVOICE', 'VAT_RETURN', 'DOCUMENT', 'OTHER']);
            $table->string('source_resource_id');
            $table->uuid('document_id')->nullable();
            $table->string('checksum_sha256', 64);
            $table->text('description');
            $table->string('status', 20);
            $table->foreignUuid('added_by')->constrained('users');
            $table->timestamp('added_at')->useCurrent();
            $table->uuid('previous_version_id')->nullable();
            $table->foreign('previous_version_id')->references('id')->on('audit_evidence');
            $table->boolean('legal_hold')->default(false);

            $table->index(['audit_case_id', 'source_resource_type', 'source_resource_id', 'status'], 'audit_evidence_active_citation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_evidence');
    }
};
